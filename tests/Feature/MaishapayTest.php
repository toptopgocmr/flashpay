<?php

namespace Tests\Feature;

use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** MaishaPay : collecte / versement / remboursement mobile money, carte, services (crédit, TV, factures), webhook. */
class MaishapayTest extends TestCase
{
    protected array $sent = [];
    protected array $status = [];   // transactionId MaishaPay → statut renvoyé par check
    protected int $seq = 1000;
    protected array $ids = [];      // notre référence → transactionId MaishaPay

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.maishapay.enabled' => true, 'flashpay.maishapay.public_key' => 'MP-SBPK-test', 'flashpay.maishapay.secret_key' => 'MP-SBSK-test',
            'flashpay.maishapay.base_url' => 'https://marchand.maishapay.test/api', 'flashpay.rails.maishapay.enabled' => true,
            'flashpay.failover.enabled' => false, 'payment_methods.card_driver' => 'maishapay',
        ]);
        Http::fake(function ($req) {
            $this->sent[] = $req;
            $path = parse_url($req->url(), PHP_URL_PATH);
            $ref = $req['transactionReference'] ?? null;
            if ($ref && ! str_contains($path, '/check')) {
                $id = $this->ids[$ref] ??= (string) ++$this->seq;
            }
            return match (true) {
                str_ends_with($path, '/collect/v2/store/mobileMoney') => Http::response(['status_code' => 202, 'transactionStatus' => 'PENDING ', 'transactionId' => (int) $id, 'originatingTransactionId' => $ref], 202),
                str_ends_with($path, '/b2c/store/transfert/mobilemoney') => Http::response(['status_code' => 202, 'transactionStatus' => 'PENDING', 'transactionId' => (int) $id]),
                str_ends_with($path, '/collect/v3/store/card') => Http::response(['status' => 'success', 'data' => ['status_code' => 202, 'transactionId' => (int) $id, 'transactionStatus' => 'Pending',
                    'transactionDescription' => 'Accepted', 'paymentPage' => 'https://marchand.maishapay.test/api/collect/postRedirectionRequest/' . $id]]),
                str_contains($path, '/services/') => Http::response(['status' => 'success', 'data' => ['transaction_id' => $id, 'payment_status' => 'PENDING']], 201),
                str_ends_with($path, '/transaction/rest/v2/check') => Http::response(['status_code' => 200, 'transactionId' => (int) $req['transactionId'],
                    'transactionStatus' => $this->status[(string) $req['transactionId']] ?? 'PENDING']),
                default => Http::response(['message' => 'not found'], 404),
            };
        });
    }

    protected function user(string $phone, int $balance = 0): User
    {
        $u = User::create(['full_name' => 'JEAN TEST', 'phone' => $phone, 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        return $u->fresh('wallet');
    }

    protected function admin(): User
    {
        $a = User::create(['full_name' => 'ADMIN', 'phone' => '242060000099', 'password' => bcrypt('x'), 'status' => 'active']);
        $a->assignRole('super_admin');
        return $a;
    }

    protected function idOf(string $reference): string
    {
        return $this->ids[$reference];
    }

    public function test_mobile_transfer_collect_payout_failure_and_refund_through_maishapay(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CG', ['partner' => 'maishapay'])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'MaishaPay'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/GA', ['partner' => 'maishapay'])->assertStatus(422);

        $u = $this->user('242061112233');
        $tx = app(\App\Services\Peex\PeexFlowService::class)->mobileTransfer($u, '+242061112233', '+242051112233', 1000, ['beneficiary_name' => 'BENEF TEST']);
        $this->assertSame(['maishapay', 'maishapay', 'awaiting_source'], [$tx->source_rail, $tx->destination_rail, $tx->stage]);
        $collect = collect($this->sent)->first(fn ($r) => str_ends_with($r->url(), 'mobileMoney'));
        $this->assertSame(['0', 'MOBILEMONEY', 'MTN', '+242061112233'], [$collect['gatewayMode'], $collect['paymentChannel']['channel'], $collect['paymentChannel']['provider'], $collect['paymentChannel']['walletID']]);
        $this->assertSame(url('/api/webhooks/maishapay'), $collect['paymentChannel']['callbackUrl']);

        // Notification « SUCCESS » non crue tant que check répond PENDING
        $this->postJson('/api/webhooks/maishapay', ['transactionId' => (int) $this->idOf($tx->reference . '-C'), 'transactionStatus' => 'SUCCESS'])->assertOk();
        $this->assertSame('awaiting_source', $tx->fresh()->stage);

        // Collecte confirmée → versement Airtel lancé
        $this->status[$this->idOf($tx->reference . '-C')] = 'SUCCESS';
        Cache::flush();
        $this->postJson('/api/webhooks/maishapay', ['transactionId' => (int) $this->idOf($tx->reference . '-C')])->assertOk();
        $this->assertSame('awaiting_destination', $tx->fresh()->stage);
        $payout = collect($this->sent)->first(fn ($r) => str_contains($r->url(), 'b2c/store'));
        $this->assertSame(['AIRTEL', '+242051112233', '1000'], [$payout['paymentChannel']['provider'], $payout['paymentChannel']['walletID'], $payout['order']['amount']]);

        // Versement refusé (confirmé) → remboursement MaishaPay vers le numéro MTN débité
        $this->status[$this->idOf($tx->reference)] = 'FAILED';
        Artisan::call('remit:sync');
        $this->assertSame(['processing', 'awaiting_refund'], [$tx->fresh()->status, $tx->fresh()->stage]);
        $refund = RemitRequest::where('method', 'refund')->firstOrFail();
        $call = collect($this->sent)->filter(fn ($r) => str_contains($r->url(), 'b2c/store'))->last();
        $this->assertSame(['MTN', '+242061112233', (string) ($tx->amount + $tx->fee)], [$call['paymentChannel']['provider'], $call['paymentChannel']['walletID'], $call['order']['amount']]);

        $this->status[$this->idOf($refund->reference)] = 'SUCCESS';
        Artisan::call('remit:sync');
        $this->assertSame('reversed', $tx->fresh()->status);
        $this->assertStringContainsString('via MaishaPay', (string) $tx->fresh()->failure_reason);
        $this->assertTrue(Transaction::where('reference', $tx->reference . '-RMB')->where('destination_rail', 'maishapay')->exists());

        // Console : passerelle et frais par opération
        $g = \App\Support\TransactionPresenter::gateway($tx->fresh());
        $this->assertSame(['MaishaPay', 'MaishaPay'], [$g['in']['partner'], $g['out']['partner']]);
        $legs = collect(\App\Support\PartnerFees::of($tx->fresh())['legs']);
        $this->assertSame(['collect', 'payout', 'refund'], $legs->pluck('kind')->all());
    }

    public function test_card_deposit_through_maishapay_payment_page(): void
    {
        $u = $this->user('242069990088');
        \App\Models\LinkedAccount::create(['user_id' => $u->id, 'type' => 'card', 'card_brand' => 'Mastercard', 'card_last4' => '5100', 'account_holder' => 'JEAN TEST', 'is_default' => true, 'country' => 'CG']);
        $tx = app(\App\Services\Peex\PeexFlowService::class)->cardDeposit($u->fresh(), 5000);
        $this->assertSame('awaiting_card', $tx->stage);
        $this->assertStringStartsWith('https://marchand.maishapay.test/api/collect/postRedirectionRequest/', $tx->meta['checkout_url']);
        $card = collect($this->sent)->first(fn ($r) => str_ends_with($r->url(), 'store/card'));
        $this->assertSame(['CARD', 'MASTERCARD'], [$card['paymentChannel']['channel'], $card['paymentChannel']['provider']]);
        $this->assertStringContainsString('/api/card-checkout/', $card['paymentChannel']['callbackUrl']);

        // Retour du client sur la page FlashPay : statut relu → wallet crédité
        $req = RemitRequest::where('method', 'checkout')->firstOrFail();
        $this->status[$req->partner_ref] = 'APPROVED';
        $this->get('/api/card-checkout/' . $tx->source_external_ref)->assertOk();
        $this->assertSame('successful', $tx->fresh()->status);
        $this->assertSame(5000, (int) $u->wallet->fresh()->balance);
    }

    public function test_airtime_tv_and_bills_paid_from_wallet_with_refund_on_failure(): void
    {
        $u = $this->user('242069990077', 20000);
        $this->actingAs($u, 'sanctum');
        $cat = $this->getJson('/api/pay/services')->assertOk()->json();
        $this->assertTrue($cat['available']);
        $this->assertSame(['airtime', 'paytv', 'utility'], collect($cat['services'])->pluck('key')->all());

        $svc = app(\App\Services\Bills\BillPaymentService::class);
        $air = $svc->pay($u, 'airtime', 1000, ['provider' => 'airtel', 'phone' => '+242051112233']);
        $this->assertSame(['airtime', 'processing'], [$air->type, $air->status]);
        $this->assertSame(19000, (int) $u->wallet->fresh()->balance);
        $call = collect($this->sent)->first(fn ($r) => str_contains($r->url(), 'services/airtime/topup'));
        $this->assertSame(['airtel', 1000, 'MP-SBPK-test'], [$call['network'], $call['amount'], $call['publicApiKey']]);

        $this->status[$this->idOf($air->reference)] = 'SUCCESS';
        Artisan::call('remit:sync');
        $this->assertSame('successful', $air->fresh()->status);

        // Abonnement TV refusé → montant rendu sur le wallet + ligne « Remboursement »
        $tv = $svc->pay($u->fresh(), 'paytv', 5000, ['provider' => 'canal_plus', 'decoder_number' => '1234567890', 'package' => 'EVASION', 'duration_months' => 1]);
        $this->assertSame(14000, (int) $u->wallet->fresh()->balance);
        $this->status[$this->idOf($tv->reference)] = 'FAILED';
        Artisan::call('remit:sync');
        $this->assertSame('reversed', $tv->fresh()->status);
        $this->assertSame(19000, (int) $u->wallet->fresh()->balance);
        $this->assertTrue(Transaction::where('reference', $tv->reference . '-RMB')->exists());

        // Facture : solde insuffisant refusé avant tout débit
        $this->expectExceptionMessage('Solde insuffisant');
        $svc->pay($u->fresh(), 'utility', 50000, ['provider' => 'snel', 'account_number' => 'C-001']);
    }
}

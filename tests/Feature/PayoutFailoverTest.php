<?php

namespace Tests\Feature;

use App\Models\PeexRequest;
use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Collecte PEEX réussie mais versement PEEX refusé (« Invalid destination country code ») :
 *  - bascule automatique vers un autre partenaire du même environnement (PawaPay) ;
 *  - sinon remboursement mobile money, et s'il échoue aussi : wallet OfficePay recrédité ;
 *  - débit effectué / crédit en échec affichés clairement.
 */
class PayoutFailoverTest extends TestCase
{
    protected array $sent = [];
    protected string $pawaPayout = 'COMPLETED';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config(['flashpay.peex.verify_accounts' => false, 'flashpay.peex.check_balance' => false, 'flashpay.peex.secret_key' => 'k',
            'flashpay.peex.sandbox' => false, 'flashpay.rails.peex.enabled' => true]);
        Http::fake(function (HttpRequest $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $d = $r->data();
            $this->sent[] = [$r->method(), $path, $d];
            return match (true) {
                str_ends_with($path, 'collection/request_payment') => Http::response(['id' => 1, 'track_id' => $d['track_id'], 'status' => 'pending']),
                str_ends_with($path, 'disbursement/request_payment') => Http::response(['error' => ['statusCode' => 422, 'message' => 'Invalid destination country code!']], 422),
                str_contains($path, 'all_requests') => Http::response([]),
                str_ends_with($path, '/me') => Http::response(['name' => 'GMC', 'is_activated' => true]),
                $path === '/v2/predict-provider' => Http::response(['country' => 'COG', 'provider' => 'AIRTEL_COG', 'phoneNumber' => preg_replace('/\D/', '', $d['phoneNumber'])]),
                $path === '/v2/active-conf' => Http::response(['countries' => [['country' => 'COG', 'providers' => [['provider' => 'AIRTEL_COG', 'displayName' => 'Airtel',
                    'currencies' => [['currency' => 'XAF', 'operationTypes' => ['DEPOSIT' => ['status' => 'OPERATIONAL'], 'PAYOUT' => ['status' => 'OPERATIONAL']]]]]]]]]),
                $path === '/v2/wallet-balances' => Http::response(['balances' => [['country' => 'COG', 'currency' => 'XAF', 'balance' => '500000']]]),
                $r->method() === 'POST' && $path === '/v2/payouts' => Http::response(['payoutId' => $d['payoutId'], 'status' => 'ACCEPTED']),
                str_starts_with($path, '/v2/payouts/') => Http::response(['status' => 'FOUND', 'data' => ['status' => $this->pawaPayout]]),
                default => Http::response(['ok' => true]),
            };
        });
    }

    protected function pawapay(string $baseUrl): void
    {
        config(['flashpay.pawapay.enabled' => true, 'flashpay.pawapay.token' => 't', 'flashpay.pawapay.base_url' => $baseUrl,
            'flashpay.rails.pawapay_momo.enabled' => true]);
    }

    /** Transfert MTN → Airtel, collecte PEEX confirmée : retourne la transaction après la tentative de versement. */
    protected function transferAndPay(): Transaction
    {
        $u = User::create(['full_name' => 'BASILE ZONI', 'phone' => '242061000551', 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        $this->actingAs($u->fresh(), 'sanctum');
        $tx = $this->postJson('/api/pay/transfer', [
            'source' => 'mobile', 'source_phone' => '+242061000551', 'destination_phone' => '+242055000552',
            'deliver_to' => 'mobile', 'amount' => 1000, 'beneficiary_name' => 'JEAN MOUKALA',
        ])->assertStatus(202)->json();
        $collect = PeexRequest::where('service', 'collect')->firstOrFail();
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('peex:peex_callback')])
            ->postJson('/api/webhooks/peex/collect', [['track_id' => $collect->track_id, 'status' => 'paid', 'payment_proof' => 'x']])->assertOk();
        return Transaction::findOrFail($tx['id']);
    }

    public function test_peex_payout_refused_switches_to_pawapay_same_environment(): void
    {
        $this->pawapay('https://api.pawapay.io'); // production, comme PEEX
        $tx = $this->transferAndPay();

        $this->assertSame('pawapay_momo', $tx->destination_rail);
        $this->assertSame('awaiting_destination', $tx->stage);
        $this->assertSame('PEEX', $tx->meta['payout_attempts'][0]['partner']);
        $this->assertStringContainsString('Invalid destination country code', $tx->meta['payout_attempts'][0]['error']);
        $p = collect($this->sent)->first(fn ($s) => $s[1] === '/v2/payouts');
        $this->assertSame('242055000552', $p[2]['recipient']['accountDetails']['phoneNumber']);

        Artisan::call('remit:sync');
        $this->assertSame('successful', $tx->fresh()->status);
        $this->assertSame(0, PeexRequest::where('track_id', 'like', '%-R%')->count()); // pas de remboursement
    }

    public function test_no_switch_to_sandbox_then_refund_fails_so_wallet_is_credited(): void
    {
        $this->pawapay('https://api.sandbox.pawapay.io'); // sandbox ≠ PEEX production : jamais de bascule
        $tx = $this->transferAndPay();

        $this->assertSame('peex', $tx->destination_rail);
        $this->assertFalse(collect($this->sent)->contains(fn ($s) => $s[1] === '/v2/payouts'));
        // Versement PEEX refusé, remboursement PEEX refusé aussi → wallet OfficePay recrédité (montant + frais)
        $this->assertSame('reversed', $tx->status);
        $wallet = Wallet::where('user_id', $tx->initiated_by)->first();
        $this->assertSame((int) ($tx->amount + $tx->fee), (int) $wallet->balance);
        $this->assertStringStartsWith('Débit effectué', $tx->failure_reason);
        $this->assertStringContainsString('Crédit en échec vers +242055000552', $tx->failure_reason);

        $o = \App\Support\TransactionPresenter::outcome($tx);
        $this->assertStringContainsString('prélevés sur +242061000551', $o['debit']);
        $this->assertStringContainsString('Invalid destination country code', $o['credit']);
        $this->assertTrue($o['refund_done']);
        $this->assertStringContainsString('wallet OfficePay', $o['refund']);

        $r = $this->getJson('/api/pay/transactions/' . $tx->id . '/status')->assertOk();
        $this->assertSame('Débit effectué, mais le crédit du bénéficiaire a échoué : montant et frais remboursés sur votre wallet OfficePay.', $r->json('message'));
        $this->assertTrue($r->json('outcome.refund_done'));
    }
}

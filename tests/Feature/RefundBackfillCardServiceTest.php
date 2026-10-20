<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\TransactionPresenter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundBackfillCardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
    }

    protected function client(string $phone, int $balance = 0): User
    {
        $u = User::create(['full_name' => 'CLIENT TEST', 'phone' => $phone, 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        return $u->fresh('wallet');
    }

    public function test_old_refunds_get_a_journal_line_without_changing_balances(): void
    {
        $u = $this->client('242069990071', 2060);
        $w = $u->wallet;
        // Ancien remboursement sur le wallet : écritures « Remboursement » sur l'opération d'origine
        $tx = Transaction::create(['reference' => 'FP-OLDREFUND01', 'type' => 'p2p', 'source_rail' => 'peex', 'source_account' => '+242067621919',
            'destination_rail' => 'digitwace', 'destination_account' => '+221770127544', 'amount' => 2000, 'fee' => 60, 'currency' => 'XAF',
            'status' => 'reversed', 'initiated_by' => $u->id, 'meta' => ['outcome' => ['refund' => ['done' => true, 'to' => 'wallet']]]]);
        $led = app(\App\Services\LedgerService::class);
        $led->recordDoubleEntry($tx, 'peex:+242067621919', 'flashpay:suspense', 2000);
        $led->recordDoubleEntry($tx, 'peex:+242067621919', 'flashpay:fees', 60);
        $led->recordDoubleEntry($tx, 'flashpay:suspense', "wallet:{$w->id}", 2000, null, 'Remboursement sur wallet');
        $led->recordDoubleEntry($tx, 'flashpay:fees', "wallet:{$w->id}", 60, null, 'Remboursement des frais');
        $before = LedgerEntry::count();

        (include database_path('migrations/2026_10_20_000001_backfill_refund_transactions.php'))->up();

        $rb = Transaction::where('reference', 'FP-OLDREFUND01-RMB')->firstOrFail();
        $this->assertSame(['refund', 'successful', 2060, 'wallet', $w->id], [$rb->type, $rb->status, (int) $rb->amount, $rb->destination_rail, (int) $rb->destination_wallet_id]);
        $this->assertSame(4, LedgerEntry::where('transaction_id', $rb->id)->count());
        $this->assertSame($before, LedgerEntry::count());
        $this->assertSame('FP-OLDREFUND01-RMB', $tx->fresh()->meta['refund_transaction']);
        // Rejouée : rien de plus
        (include database_path('migrations/2026_10_20_000001_backfill_refund_transactions.php'))->up();
        $this->assertSame(1, Transaction::where('type', 'refund')->count());

        // Historique du client : la ligne « Remboursement (entrant) » apparaît
        $line = collect($this->actingAs($u, 'sanctum')->getJson('/api/transactions')->assertOk()->json('data'))->firstWhere('reference', 'FP-OLDREFUND01-RMB');
        $this->assertSame(['credit', 'Remboursement (entrant)', 2060], [$line['direction'], $line['label'], $line['signed_amount']]);
    }

    public function test_card_checkout_service_found_in_live_services_and_card_brand_shown(): void
    {
        config(['flashpay.digitwace.enabled' => true, 'flashpay.digitwace.api' => 'partner', 'flashpay.digitwace.public_key' => 'pk', 'flashpay.digitwace.private_key' => 'sk',
            'flashpay.digitwace.base_url' => 'https://sandbox-payinws.wacepay.test/api/v1', 'flashpay.digitwace.card_service_id' => null]);
        Http::fake(function ($req) {
            $u = $req->url();
            return match (true) {
                str_contains($u, 'get-token') => Http::response(['status' => 2000, 'data' => ['token' => 'tok']]),
                str_contains($u, 'payments/services') => Http::response(['status' => 2000, 'data' => [
                    ['id' => 'svc-mtn', 'name' => 'MTN Mobile Money', 'country' => ['iso2' => 'CG'], 'operator' => 'MTN'],
                    ['id' => 'svc-card', 'name' => 'Carte bancaire VISA / MASTERCARD', 'paymentMethod' => 'CARD'], // sans pays
                ]]),
                str_contains($u, 'payout/services') => Http::response(['status' => 2000, 'data' => []]),
                default => Http::response(['status' => 2000, 'data' => []]),
            };
        });
        $this->assertSame('svc-card', app(\App\Services\Digitwace\DigitwaceClient::class)->checkoutServiceFor('card', 'CG'));

        // Libellés : carte avec sa marque, marchand, agent
        $this->assertSame('Visa •••• 4242', TransactionPresenter::cardLabel(['card_brand' => 'Visa', 'card_last4' => '4242']));
        $this->assertSame('Carte bancaire', TransactionPresenter::cardLabel([]));
        $u = $this->client('242069990072');
        $pay = Transaction::create(['reference' => 'FP-MERCHANT01', 'type' => 'merchant_payment', 'source_rail' => 'card', 'destination_rail' => 'wallet',
            'destination_wallet_id' => $u->wallet->id, 'amount' => 500, 'currency' => 'XAF', 'status' => 'successful', 'initiated_by' => $u->id,
            'meta' => ['merchant_name' => 'Boutique Test', 'card_brand' => 'Mastercard', 'card_last4' => '5100']]);
        $g = TransactionPresenter::gateway($pay);
        $this->assertSame('Mastercard •••• 5100', $g['in']['operator']);
        $this->assertSame(['Marchand · Boutique Test', 'merchant'], [$g['out']['operator'], $g['out']['kind']]);
        $rows = collect(TransactionPresenter::details($pay))->pluck('value', 'label');
        $this->assertSame('Mastercard •••• 5100', $rows['Compte débité']);
    }
}

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

/**
 * Remboursement par le partenaire qui a collecté, sur le compte débité :
 * collecte pawaPay (MTN) → versement en échec → versement de remboursement pawaPay vers le
 * numéro MTN du payeur → opération « Remboursement » visible dans le journal du client.
 */
class RefundToSourcePartnerTest extends TestCase
{
    protected array $sent = [];
    protected array $status = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.pawapay.enabled' => true, 'flashpay.pawapay.token' => 'tok-test',
            'flashpay.pawapay.base_url' => 'https://api.sandbox.pawapay.io',
            'flashpay.rails.pawapay_momo.enabled' => true, 'flashpay.rails.pawapay.enabled' => true,
            'flashpay.failover.enabled' => false,
        ]);
        Http::fake(function ($req) {
            $this->sent[] = $req;
            $path = parse_url($req->url(), PHP_URL_PATH);
            $id = basename($path);
            return match (true) {
                $path === '/v2/predict-provider' => Http::response(['country' => 'COG', 'phoneNumber' => preg_replace('/\D/', '', $req['phoneNumber']),
                    'provider' => str_starts_with(substr(preg_replace('/\D/', '', $req['phoneNumber']), 3), '06') ? 'MTN_MOMO_COG' : 'AIRTEL_COG']),
                $req->method() === 'POST' && $path === '/v2/deposits' => Http::response(['depositId' => $req['depositId'], 'status' => 'ACCEPTED']),
                $req->method() === 'POST' && $path === '/v2/payouts' => Http::response(['payoutId' => $req['payoutId'], 'status' => 'ACCEPTED']),
                str_starts_with($path, '/v2/deposits/'), str_starts_with($path, '/v2/payouts/') => Http::response(['status' => 'FOUND', 'data' => [
                    'status' => $this->status[$id] ?? 'PROCESSING',
                    'failureReason' => ($this->status[$id] ?? '') === 'FAILED' ? ['failureCode' => 'RECIPIENT_NOT_FOUND', 'failureMessage' => 'Recipient not found'] : null]]),
                $path === '/v2/active-conf' => Http::response(['countries' => [['country' => 'COG', 'providers' => [
                    ['provider' => 'MTN_MOMO_COG', 'displayName' => 'MTN', 'currencies' => [['currency' => 'XAF', 'operationTypes' => ['DEPOSIT' => ['status' => 'OPERATIONAL'], 'PAYOUT' => ['status' => 'OPERATIONAL']]]]],
                    ['provider' => 'AIRTEL_COG', 'displayName' => 'Airtel', 'currencies' => [['currency' => 'XAF', 'operationTypes' => ['DEPOSIT' => ['status' => 'OPERATIONAL'], 'PAYOUT' => ['status' => 'OPERATIONAL']]]]],
                ]]]]),
                $path === '/v2/wallet-balances' => Http::response(['balances' => [['country' => 'COG', 'currency' => 'XAF', 'balance' => '1000000']]]),
                default => Http::response(['message' => 'not found'], 404),
            };
        });
    }

    public function test_failed_payout_is_refunded_by_the_collecting_partner_to_the_debited_number(): void
    {
        $admin = User::create(['full_name' => 'ADMIN', 'phone' => '242060000099', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CG', ['partner' => 'pawapay'])->assertOk();

        $u = User::create(['full_name' => 'CLIENT TEST', 'phone' => '242061112233', 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        $wallet = Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);

        $tx = app(\App\Services\Peex\PeexFlowService::class)->mobileTransfer($u->fresh(), '+242061112233', '+242051112233', 2000, ['beneficiary_name' => 'BENEF TEST']);
        $this->assertSame('pawapay_momo', $tx->source_rail);
        $total = (int) $tx->amount + (int) $tx->fee;

        // Collecte confirmée, versement lancé puis refusé (confirmé par l'API)
        $dep = RemitRequest::where('method', 'deposit')->firstOrFail();
        $this->status[$dep->partner_ref] = 'COMPLETED';
        Artisan::call('remit:sync');
        $pay = RemitRequest::where('method', 'payout')->firstOrFail();
        $this->status[$pay->partner_ref] = 'FAILED';
        Artisan::call('remit:sync');

        // Remboursement lancé chez pawaPay (pas PEEX, pas le wallet) vers le numéro MTN débité
        $tx->refresh();
        $this->assertSame(['processing', 'awaiting_refund'], [$tx->status, $tx->stage]);
        $ref = RemitRequest::where('method', 'refund')->firstOrFail();
        $this->assertSame($tx->reference . '-R', $ref->reference);
        $call = collect($this->sent)->filter(fn ($q) => $q->method() === 'POST' && str_ends_with($q->url(), '/v2/payouts'))->last();
        $this->assertSame('242061112233', $call['recipient']['accountDetails']['phoneNumber']);
        $this->assertSame('MTN_MOMO_COG', $call['recipient']['accountDetails']['provider']);
        $this->assertSame((string) $total, $call['amount']);
        $this->assertSame(0, (int) $wallet->fresh()->balance);

        // Remboursement confirmé → transaction « remboursée » + opération « Remboursement » distincte
        $this->status[$ref->partner_ref] = 'COMPLETED';
        Artisan::call('remit:sync');
        $tx->refresh();
        $this->assertSame('reversed', $tx->status);
        $this->assertStringContainsString('via PawaPay', (string) $tx->failure_reason);
        $rb = Transaction::where('reference', $tx->reference . '-RMB')->firstOrFail();
        $this->assertSame(['refund', 'successful', $total, 'pawapay_momo', '+242061112233'],
            [$rb->type, $rb->status, (int) $rb->amount, $rb->destination_rail, $rb->destination_account]);
        $this->assertSame($rb->reference, $tx->meta['refund_transaction']);
        $this->assertSame(0, (int) $wallet->fresh()->balance); // rien sur le wallet : rendu sur le mobile

        // Journal du client : la ligne de remboursement est un crédit
        $lines = collect($this->actingAs($u, 'sanctum')->getJson('/api/transactions')->assertOk()->json('data'));
        $line = $lines->firstWhere('reference', $rb->reference);
        $this->assertNotNull($line);
        $this->assertSame('credit', $line['direction']);
        $this->assertSame('Remboursement (entrant)', $line['label']);
        $this->assertSame($total, $line['signed_amount']);

        // Rejeu d'un échec : aucun second remboursement
        $before = Transaction::where('type', 'refund')->count();
        app(\App\Services\SwitchService::class)->onDestinationFailed($tx->fresh(), 'rejeu');
        $this->assertSame($before, Transaction::where('type', 'refund')->count());
    }

    public function test_console_refunds_an_orphan_debit_through_the_collecting_partner_only_when_not_credited(): void
    {
        $admin = User::create(['full_name' => 'ADMIN', 'phone' => '242060000099', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $u = User::create(['full_name' => 'CLIENT TEST', 'phone' => '242061112244', 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        $sw = app(\App\Services\SwitchService::class);

        // Opération « orpheline » : collecte payée chez pawaPay, transaction restée en échec, rien au grand livre
        $tx = $sw->createPending(['type' => 'p2p', 'source_rail' => 'pawapay_momo', 'source_account' => '+242061112244',
            'destination_rail' => 'pawapay_momo', 'destination_account' => '+242051112233', 'amount' => 1000, 'fee' => 30,
            'currency' => 'XAF', 'initiated_by' => $u->id, 'meta' => ['source_country' => 'CG', 'destination_country' => 'CG']]);
        RemitRequest::create(['reference' => $tx->reference . '-C', 'transaction_id' => $tx->id, 'partner' => 'pawapay_momo', 'method' => 'deposit',
            'status' => 'successful', 'partner_ref' => 'dep-1', 'finalized_at' => now()]);
        $tx->update(['status' => 'failed', 'stage' => null, 'failure_reason' => 'erreur interne']);

        // La réconciliation signale l'orpheline PawaPay et le client à rembourser
        $rep = app(\App\Services\Ops\ReconciliationService::class)->run();
        $this->assertSame($tx->reference, $rep->results['to_refund'][0]['reference'] ?? null);
        $this->assertSame('PawaPay', $rep->results['to_refund'][0]['refund_via']);
        $this->assertSame('Collecte payée, opération en échec non remboursée', $rep->results['partner_orphans'][0]['issue'] ?? null);

        $e = $sw->refundEligibility($tx->fresh());
        $this->assertTrue($e['possible']);
        $this->assertSame(['PawaPay', '+242061112244', 1030], [$e['partner'], $e['to'], $e['amount']]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refund-source", ['reason' => 'collecte payée, versement jamais lancé'])
            ->assertOk()->assertJsonPath('stage', 'awaiting_refund');
        $call = collect($this->sent)->filter(fn ($q) => $q->method() === 'POST' && str_ends_with($q->url(), '/v2/payouts'))->last();
        $this->assertSame('242061112244', $call['recipient']['accountDetails']['phoneNumber']);
        $this->assertSame('1030', $call['amount']);
        // Collecte comptabilisée avant le remboursement (grand livre équilibré)
        $this->assertTrue(\App\Models\LedgerEntry::where('transaction_id', $tx->id)->where('account', 'pawapay_momo:+242061112244')->where('type', 'debit')->exists());
        // Deuxième demande : refusée (remboursement déjà en cours)
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refund-source", ['reason' => 'doublon'])->assertStatus(422);

        // Opération réussie (bénéficiaire crédité) : jamais remboursable
        $ok = $sw->createPending(['type' => 'p2p', 'source_rail' => 'pawapay_momo', 'source_account' => '+242061112244',
            'destination_rail' => 'pawapay_momo', 'destination_account' => '+242051112233', 'amount' => 500, 'fee' => 0,
            'currency' => 'XAF', 'initiated_by' => $u->id]);
        $ok->update(['status' => 'successful', 'stage' => null]);
        $this->assertFalse($sw->refundEligibility($ok->fresh())['possible']);
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$ok->id}/refund-source", ['reason' => 'test'])->assertStatus(422);

        // Échec AVANT débit (collecte jamais payée) : rien à rembourser
        $nodebit = $sw->createPending(['type' => 'p2p', 'source_rail' => 'pawapay_momo', 'source_account' => '+242061112244',
            'destination_rail' => 'pawapay_momo', 'destination_account' => '+242051112233', 'amount' => 700, 'fee' => 0,
            'currency' => 'XAF', 'initiated_by' => $u->id]);
        $nodebit->update(['status' => 'failed', 'stage' => null]);
        $this->assertStringContainsString('Aucun débit', (string) $sw->refundEligibility($nodebit->fresh())['reason']);
    }
}

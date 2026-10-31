<?php

namespace Tests\Feature;

use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Opérations restées « En attente » : décision systématique selon les réponses des partenaires
 * (transactions:resolve-pending, planifiée toutes les 5 min, et bouton de la console).
 */
class PendingResolverTest extends TestCase
{
    protected int $peexHttp = 422;
    protected array $peexStatus = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.maishapay.enabled' => true, 'flashpay.maishapay.public_key' => 'MP-LIVEPK-x', 'flashpay.maishapay.secret_key' => 'MP-LIVESK-x',
            'flashpay.maishapay.base_url' => 'https://marchand.maishapay.test/api', 'flashpay.rails.maishapay.enabled' => true,
            'flashpay.peex.secret_key' => 'k', 'flashpay.peex.sandbox' => false, 'flashpay.rails.peex.enabled' => true,
            'flashpay.peex.verify_accounts' => false, 'flashpay.peex.check_balance' => false, 'flashpay.failover.enabled' => true,
        ]);
        Http::fake(function ($req) {
            $path = (string) parse_url($req->url(), PHP_URL_PATH);
            return match (true) {
                str_ends_with($path, '/b2c/store/transfert/mobilemoney') => Http::response(['title' => 'Insufficient Balance',
                    'errors' => ['total amount' => 'Solde insuffisant. Disponible: 0.00000000, Requis: 100.00000000'], 'status_code' => 403], 403),
                str_ends_with($path, 'disbursement/request_payment') => Http::response(['error' => ['statusCode' => $this->peexHttp, 'message' => 'Invalid destination country code!']], $this->peexHttp),
                str_contains($path, 'all_requests') => Http::response(array_map(fn ($t, $st) => ['track_id' => $t, 'status' => $st], array_keys($this->peexStatus), $this->peexStatus)),
                str_ends_with($path, '/me') => Http::response(['name' => 'GMC', 'is_activated' => true, 'disbursement_solde' => 1_000_000]),
                default => Http::response(['ok' => true]),
            };
        });
    }

    protected function walletTransfer(): array
    {
        $admin = User::create(['full_name' => 'ADMIN', 'phone' => '242060000099', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CG', ['partner' => 'maishapay'])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CG', ['routing_mode' => 'mixed', 'payout_backups' => ['peex']])->assertOk();

        $u = User::create(['full_name' => 'JEAN TEST', 'phone' => '242061112233', 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 5000, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        $tx = app(\App\Services\Peex\PeexFlowService::class)->transfer($u->fresh(), 'wallet', null, '+242067601919', 100, ['beneficiary_name' => 'BENEF TEST']);
        return [$tx->fresh(), $u];
    }

    protected function age(Transaction $tx, string $stage): void
    {
        DB::table('transactions')->where('id', $tx->id)->update(['stage' => $stage, 'updated_at' => now()->subHour()]);
    }

    public function test_frozen_handling_stage_with_all_partners_refused_is_rejected_and_refunded(): void
    {
        $this->peexHttp = 502;
        [$tx, $u] = $this->walletTransfer();
        // Situation d'avant le correctif : PEEX a refusé, mais le traitement s'est interrompu (étape figée)
        PeexRequest::where('transaction_id', $tx->id)->update(['status' => 'error', 'message' => 'Invalid destination country code!', 'finalized_at' => now()]);
        $this->age($tx, 'awaiting_destination:handling');

        // Simulation : rien ne change
        Artisan::call('transactions:resolve-pending');
        $this->assertSame(['processing', 'awaiting_destination:handling'], [$tx->fresh()->status, $tx->fresh()->stage]);

        Artisan::call('transactions:resolve-pending', ['--force' => true]);
        $tx = $tx->fresh();
        $this->assertSame(['reversed', null], [$tx->status, $tx->stage]);
        $this->assertSame(5000, (int) Wallet::where('user_id', $u->id)->value('balance'));
        $this->assertStringContainsString('Invalid destination country code', $tx->failure_reason);

        Artisan::call('transactions:resolve-pending', ['--force' => true, '--all' => true]);
        $this->assertSame(5000, (int) Wallet::where('user_id', $u->id)->value('balance')); // jamais remboursé deux fois
    }

    public function test_open_payout_is_never_cancelled_until_the_partner_answers(): void
    {
        $this->peexHttp = 502;
        [$tx, $u] = $this->walletTransfer();
        $this->age($tx, 'awaiting_destination');

        Artisan::call('transactions:resolve-pending', ['--force' => true, '--all' => true]);
        $this->assertSame(['processing', 'awaiting_destination'], [$tx->fresh()->status, $tx->fresh()->stage]);
        $this->assertSame(4899, (int) Wallet::where('user_id', $u->id)->value('balance'));

        // PEEX répond « error » : la relecture applique le refus → rejet + remboursement
        $d1 = PeexRequest::where('track_id', 'like', '%-D1')->firstOrFail();
        $this->peexStatus[$d1->track_id] = 'error';
        $this->age($tx, 'awaiting_destination');
        Artisan::call('transactions:resolve-pending', ['--force' => true]);
        $this->assertSame('reversed', $tx->fresh()->status);
        $this->assertSame(5000, (int) Wallet::where('user_id', $u->id)->value('balance'));
    }

    public function test_console_cancel_all_cancels_unconfirmed_collections(): void
    {
        $u = User::create(['full_name' => 'JEAN TEST', 'phone' => '242061112244', 'password' => bcrypt('x'), 'status' => 'active']);
        $tx = Transaction::create(['reference' => 'FP-TESTPENDING1', 'type' => 'p2p', 'status' => 'processing', 'stage' => 'awaiting_source',
            'source_rail' => 'peex', 'source_account' => '242061112244', 'destination_rail' => 'wallet', 'amount' => 100, 'fee' => 0, 'currency' => 'XAF',
            'initiated_by' => $u->id]);
        $this->age($tx, 'awaiting_source');

        $admin = User::create(['full_name' => 'ADMIN', 'phone' => '242060000098', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $sim = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/transactions/resolve-pending', ['dry_run' => true, 'all' => true])->assertOk()->json();
        $this->assertSame('à annuler', collect($sim['data'])->firstWhere('reference', 'FP-TESTPENDING1')['action']);
        $this->assertSame('processing', $tx->fresh()->status);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/transactions/resolve-pending', ['dry_run' => false, 'all' => true])->assertOk();
        $this->assertSame('failed', $tx->fresh()->status);
    }
}

<?php

namespace Tests\Feature;

use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Transfert depuis le wallet : MaishaPay refuse le versement (403 « Insufficient Balance »),
 * bascule vers PEEX (incident FP-9GM7NBUF79PM du 27/10/2026).
 *  - le message MaishaPay est lisible (« Insufficient Balance : Solde insuffisant… ») ;
 *  - l'échec de l'ANCIEN partenaire (MaishaPay) ne doit jamais être appliqué au versement
 *    PEEX en cours (sinon remboursement pendant que PEEX verse peut-être encore) ;
 *  - quand PEEX refuse aussi, le wallet est recrédité et la transaction ne reste pas « en attente ».
 */
class PayoutAllRefusedTest extends TestCase
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

    public function test_both_partners_refuse_wallet_is_refunded_with_readable_reason(): void
    {
        [$tx, $u] = $this->walletTransfer();

        $this->assertSame(['reversed', null], [$tx->status, $tx->stage]);
        $this->assertSame(5000, (int) Wallet::where('user_id', $u->id)->value('balance'));
        $this->assertSame('MaishaPay (HTTP 403) : Insufficient Balance : Solde insuffisant. Disponible: 0.00000000, Requis: 100.00000000', $tx->meta['payout_attempts'][0]['error']);
        Artisan::call('remit:sync');
        $this->assertSame('reversed', $tx->fresh()->status);
        $this->assertSame(5000, (int) Wallet::where('user_id', $u->id)->value('balance')); // jamais remboursé deux fois
    }

    public function test_stale_maishapay_failure_is_not_applied_while_peex_payout_is_in_progress(): void
    {
        $this->peexHttp = 502; // réponse PEEX incertaine : le versement est peut-être parti
        [$tx, $u] = $this->walletTransfer();
        $this->assertSame(['processing', 'awaiting_destination', 'peex'], [$tx->status, $tx->stage, $tx->destination_rail]);

        // Synchro : l'échec MaishaPay (ancien partenaire) ne rembourse PAS pendant que PEEX est en cours
        Artisan::call('remit:sync');
        $tx = $tx->fresh();
        $this->assertSame(['processing', 'awaiting_destination'], [$tx->status, $tx->stage]);
        $this->assertSame(4899, (int) Wallet::where('user_id', $u->id)->value('balance'));

        // PEEX confirme l'échec du versement → wallet recrédité, opération close
        $d1 = PeexRequest::where('track_id', 'like', '%-D1')->firstOrFail();
        $this->peexStatus[$d1->track_id] = 'error';
        app(\App\Services\Peex\PeexStatusHandler::class)->refresh($d1);
        $tx = $tx->fresh();
        $this->assertSame(['reversed', null], [$tx->status, $tx->stage]);
        $this->assertSame(5000, (int) Wallet::where('user_id', $u->id)->value('balance'));
        $this->assertStringContainsString('PEEX', $tx->meta['outcome']['credit']['error']);
    }
}

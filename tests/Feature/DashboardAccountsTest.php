<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tableau de bord (Retraits / Recharges / Paiements + statuts) et
 * activation / désactivation des comptes par le Super Admin.
 */
class DashboardAccountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    private function admin(string $phone = '242060009999'): User
    {
        $a = User::create(['full_name' => 'Admin', 'phone' => $phone, 'password' => 'x']);
        $a->assignRole('super_admin');
        return $a;
    }

    private function client(string $phone, string $role = 'client'): User
    {
        $u = User::create(['full_name' => 'U ' . $phone, 'phone' => $phone, 'password' => bcrypt('secret123')]);
        $u->assignRole($role);
        return $u;
    }

    private function tx(string $type, string $src, string $dst, string $status, int $amount = 1000): void
    {
        Transaction::create(['initiated_by' => User::first()->id, 'reference' => Str::upper(Str::random(12)), 'type' => $type, 'source_rail' => $src, 'destination_rail' => $dst,
            'amount' => $amount, 'fee' => 0, 'currency' => 'XAF', 'status' => $status]);
    }

    public function test_dashboard_groups_withdrawals_deposits_payments_with_statuses(): void
    {
        $admin = $this->admin();
        $this->tx('cash_pickup', 'wallet', 'cash', 'successful');          // retrait QR
        $this->tx('withdrawal', 'wallet', 'peex', 'failed');               // retrait wallet
        $this->tx('cash_in', 'wallet', 'wallet', 'successful', 5000);      // recharge cash agent
        $this->tx('cash_in', 'card', 'wallet', 'reversed');                // recharge carte
        $this->tx('p2p', 'peex', 'wallet', 'processing');                  // mobile money -> wallet
        $this->tx('qr_payment', 'wallet', 'wallet', 'successful', 2000);   // paiement wallet
        $this->tx('merchant_payment', 'peex', 'wallet', 'failed');         // paiement mobile money
        $this->tx('merchant_payment', 'card', 'wallet', 'successful');      // paiement banque/carte

        $this->actingAs($admin, 'sanctum');
        $r = $this->getJson('/api/admin/dashboard?days=7')->assertOk();

        $k = $r->json('kpi');
        $this->assertSame([8, 13000, 4, 2, 1, 1], [$k['count'], $k['volume'], $k['successful'], $k['failed'], $k['reversed'], $k['processing']]);

        $fam = collect($r->json('families'))->keyBy('key');
        $this->assertSame(['withdrawals', 'deposits', 'payments', 'transfers'], $fam->keys()->all());
        $ch = fn ($f) => collect($fam[$f]['channels'])->keyBy('key');

        $this->assertSame(1, $ch('withdrawals')['withdrawal_qr']['successful']);
        $this->assertSame(1, $ch('withdrawals')['withdrawal_wallet']['failed']);
        $this->assertSame(5000, $ch('deposits')['deposit_agent']['volume']);
        $this->assertSame(1, $ch('deposits')['deposit_card']['reversed']);
        $this->assertSame(1, $ch('deposits')['deposit_mm']['processing']);
        $this->assertSame(1, $ch('payments')['payment_wallet']['count']);
        $this->assertSame(1, $ch('payments')['payment_mm']['failed']);
        $this->assertSame(1, $ch('payments')['payment_bank']['successful']);
        $this->assertSame(3, $fam['payments']['count']);
        $this->assertSame(0, $fam['transfers']['count']);

        // Liens du tableau de bord -> liste filtrée
        $this->assertSame(1, $this->getJson('/api/admin/transactions?channel=deposits&status=reversed')->json('total'));
        $this->assertSame(2, $this->getJson('/api/admin/transactions?status=failed')->json('total'));
        $this->assertSame(3, $this->getJson('/api/admin/transactions?channel=payments')->json('total'));
    }

    public function test_super_admin_deactivates_and_reactivates_accounts(): void
    {
        $admin = $this->admin();
        $other = $this->admin('242060008888');
        $c1 = $this->client('242061111111');
        $c2 = $this->client('242062222222', 'merchant');
        $token = $c1->createToken('t')->plainTextToken;

        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/admin/accounts')->assertOk()->assertJsonPath('counts.inactive', 0);

        // Un compte
        $this->postJson("/api/admin/accounts/{$c1->id}/status", ['active' => false, 'reason' => 'fraude'])->assertOk();
        $this->assertSame('suspended', $c1->fresh()->status);
        $this->assertSame('fraude', $c1->fresh()->status_reason);
        $this->assertSame(0, $c1->tokens()->count()); // déconnecté

        // Pas soi-même
        $this->postJson("/api/admin/accounts/{$admin->id}/status", ['active' => false])->assertStatus(422);

        // Tous : exclut soi-même et les super admins
        $r = $this->postJson('/api/admin/accounts/bulk-status', ['active' => false, 'all' => true])->assertOk();
        $this->assertSame('suspended', $c2->fresh()->status);
        $this->assertSame('active', $admin->fresh()->status);
        $this->assertSame('active', $other->fresh()->status);

        // Filtre + liste
        $this->assertSame(2, $this->getJson('/api/admin/accounts?status=inactive')->json('total'));

        // Réactiver tous
        $this->postJson('/api/admin/accounts/bulk-status', ['active' => true, 'all' => true])->assertOk();
        $this->assertSame('active', $c1->fresh()->status);

        // Sélection
        $this->postJson('/api/admin/accounts/bulk-status', ['active' => false, 'ids' => [$c2->id, $admin->id]])->assertOk()->assertJsonPath('affected', 1);
        $this->assertSame('active', $admin->fresh()->status);

        // Un compte désactivé ne peut plus utiliser l'API ni se connecter
        $this->app['auth']->forgetGuards();
        $this->actingAs($c2->fresh(), 'sanctum');
        $this->getJson('/api/me')->assertStatus(403)->assertJsonPath('code', 'account_disabled');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['phone' => '242062222222', 'password' => 'secret123', 'profile' => 'merchant'])->assertStatus(403);
    }

    public function test_date_range_filters(): void
    {
        $admin = $this->admin();
        $this->tx('cash_in', 'wallet', 'wallet', 'successful', 1000);
        $old = Transaction::latest('id')->first();
        $old->created_at = now()->subDays(40); $old->save();
        $this->tx('cash_in', 'wallet', 'wallet', 'successful', 2000);

        $this->actingAs($admin, 'sanctum');
        $f = now()->subDays(45)->toDateString(); $t = now()->subDays(35)->toDateString();
        $this->assertSame(1, $this->getJson("/api/admin/transactions?from={$f}&to={$t}")->json('total'));
        $this->assertSame(1, $this->getJson('/api/admin/transactions?from=' . now()->toDateString())->json('total'));
        $this->assertSame(2, $this->getJson("/api/admin/transactions?to=" . now()->toDateString())->json('total'));
        $this->assertSame(1, $this->getJson("/api/admin/transactions?from={$t}&to={$f}")->json('total')); // inversées

        $d = $this->getJson("/api/admin/dashboard?from={$f}&to={$t}")->assertOk();
        $this->assertSame(1000, $d->json('kpi.volume'));
        $this->assertSame(11, $d->json('period.days'));
        $this->assertTrue($d->json('period.custom'));
        $this->assertSame(2000, $this->getJson('/api/admin/dashboard?days=1')->json('kpi.volume'));
    }

    public function test_performance_indicators(): void
    {
        $admin = $this->admin();
        $m = $this->client('242063333333', 'merchant');
        $mw = \App\Models\Wallet::create(['user_id' => $m->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        \App\Models\Merchant::create(['user_id' => $m->id, 'business_name' => 'Boutique Top', 'qr_code_token' => 'tok1', 'validation_status' => 'approved']);
        $ag = $this->client('242064444444', 'agent');
        $aw = \App\Models\Wallet::create(['user_id' => $ag->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        \App\Models\Agent::create(['user_id' => $ag->id, 'validation_status' => 'approved', 'zone' => 'Poto-Poto']);

        $mk = fn ($type, $status, $amount, $extra) => Transaction::create($extra + ['initiated_by' => $admin->id, 'reference' => Str::upper(Str::random(12)), 'type' => $type,
            'source_rail' => 'wallet', 'destination_rail' => 'wallet', 'amount' => $amount, 'fee' => 10, 'currency' => 'XAF', 'status' => $status, 'completed_at' => now()->addSeconds(30)]);
        $mk('qr_payment', 'successful', 4000, ['destination_wallet_id' => $mw->id]);
        $mk('qr_payment', 'failed', 1000, ['destination_wallet_id' => $mw->id]);
        $mk('cash_in', 'successful', 9000, ['source_wallet_id' => $aw->id]);
        $mk('cash_out', 'successful', 1000, ['destination_wallet_id' => $aw->id]);
        $old = $mk('qr_payment', 'successful', 2000, ['destination_wallet_id' => $mw->id]);
        $old->created_at = now()->subDays(10); $old->save();

        $this->actingAs($admin, 'sanctum');
        $p = $this->getJson('/api/admin/dashboard?days=7')->assertOk()->json('performance');
        $this->assertSame('Boutique Top', $p['top_merchants'][0]['name']);
        $this->assertSame(4000, $p['top_merchants'][0]['volume']);
        $this->assertEquals(50, $p['top_merchants'][0]['success_rate']);
        $this->assertSame([1, 1, 10000], [$p['top_agents'][0]['deposits'], $p['top_agents'][0]['withdrawals'], $p['top_agents'][0]['volume']]);
        $this->assertSame(14000, $p['current']['volume']);
        $this->assertSame(2000, $p['prev']['volume']);
        $this->assertSame(4667, $p['current']['avg_ticket']);
        $this->assertNotNull($p['current']['avg_delay']);
        $pay = collect($p['by_family'])->firstWhere('key', 'payments');
        $this->assertSame([4000, 2000], [$pay['volume'], $pay['prev_volume']]);
    }
}

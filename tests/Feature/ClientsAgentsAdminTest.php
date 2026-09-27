<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Gestion des clients et des agents par le Super Admin. */
class ClientsAgentsAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    private function admin(): User
    {
        $a = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']);
        $a->assignRole('super_admin');
        return $a;
    }

    public function test_client_management(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        // Le client s'inscrit lui-même (l'admin ne peut plus créer de client)
        $this->postJson('/api/admin/clients', ['full_name' => 'Marie Client', 'phone' => '066112233', 'password' => 'secret123', 'country' => 'CG'])->assertStatus(405);
        $client = User::create(['full_name' => 'Marie Client', 'phone' => '066112233', 'password' => 'secret123']);
        $client->assignRole('client');
        \App\Models\Wallet::create(['user_id' => $client->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        $id = $client->id;
        $client = $client->fresh();
        $this->assertTrue($client->hasRole('client'));
        $this->assertNotNull($client->wallet);

        // Liste + filtres
        $this->getJson('/api/admin/clients')->assertOk()->assertJsonPath('counts.total', 1)->assertJsonPath('data.0.full_name', 'Marie Client');
        $this->assertSame(0, $this->getJson('/api/admin/clients?q=zzz')->json('total'));
        $this->assertSame(1, $this->getJson('/api/admin/clients?kyc=pending&status=active&sort=balance')->json('total'));
        // L'équipe interne n'inclut plus les clients
        $this->assertSame(0, collect($this->getJson('/api/admin/users')->json('data'))->where('id', $id)->count());

        // Pas de modification du profil client par l'admin ; KYC seulement
        $this->putJson("/api/admin/clients/{$id}", ['full_name' => 'Marie K.'])->assertStatus(405);
        $this->postJson("/api/admin/clients/{$id}/kyc", ['decision' => 'verified'])->assertOk();
        $this->assertSame('verified', $client->fresh()->kyc_status);

        // Transactions + fiche
        $client->wallet->update(['balance' => 5000]);
        Transaction::create(['initiated_by' => $id, 'reference' => Str::random(10), 'type' => 'p2p', 'source_rail' => 'wallet', 'destination_rail' => 'wallet',
            'source_wallet_id' => $client->wallet->id, 'amount' => 1000, 'fee' => 10, 'currency' => 'XAF', 'status' => 'successful']);
        $show = $this->getJson("/api/admin/clients/{$id}")->assertOk();
        $this->assertSame(1, $show->json('stats.count'));
        $this->assertSame(1000, $show->json('stats.volume_out'));
        $this->assertSame('out', $show->json('recent.0.direction'));
        $this->assertSame(1, $this->getJson("/api/admin/transactions?user={$id}")->json('total'));
        $this->assertSame(1, $this->getJson('/api/admin/clients')->json('data.0.tx_count'));

        // Gel du wallet : plus aucun débit
        $this->postJson("/api/admin/wallets/{$id}/status", ['frozen' => true])->assertOk();
        $this->assertSame(1, $this->getJson('/api/admin/clients?wallet=frozen')->json('total'));
        $this->expectException(\App\Exceptions\InsufficientFundsException::class);
        app(\App\Services\WalletService::class)->debit($client->wallet->fresh(), 100);
    }

    public function test_agent_detail_and_update(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/admin/agents', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'Awa Agent', 'phone' => '055552222', 'password' => 'secret123', 'zone' => 'Poto-Poto'])->assertCreated();
        $agent = Agent::first();
        $w = $agent->user->wallet;

        $cw = [];
        foreach ([1, 2, 3] as $i) {
            $u = User::create(['full_name' => "C{$i}", 'phone' => "24206600000{$i}", 'password' => 'x']);
            $cw[$i] = Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG'])->id;
        }
        $mk = fn ($type, $amount, $extra) => Transaction::create($extra + ['initiated_by' => $admin->id, 'reference' => Str::random(10), 'type' => $type, 'source_rail' => 'wallet',
            'destination_rail' => 'wallet', 'amount' => $amount, 'fee' => 0, 'currency' => 'XAF', 'status' => 'successful']);
        $mk('cash_in', 10000, ['source_wallet_id' => $w->id, 'destination_wallet_id' => $cw[1]]);
        $mk('cash_out', 4000, ['destination_wallet_id' => $w->id, 'source_wallet_id' => $cw[2]]);
        $mk('cash_pickup', 3000, ['destination_wallet_id' => $w->id, 'source_wallet_id' => $cw[3], 'meta' => ['agent_commission' => 150]]);
        $this->postJson("/api/admin/agents/{$agent->id}/float", ['amount' => 50000])->assertCreated();

        $d = $this->getJson("/api/admin/agents/{$agent->id}")->assertOk();
        $this->assertSame([1, 10000], [$d->json('performance.deposits.count'), $d->json('performance.deposits.volume')]);
        $this->assertSame(1, $d->json('performance.withdrawals_wallet.count'));
        $this->assertSame(1, $d->json('performance.withdrawals_qr.count'));
        $this->assertSame(150, $d->json('performance.commission'));
        $this->assertSame(3, $d->json('performance.clients_served'));
        $this->assertSame(1, $d->json('performance.float_topups.count'));
        $this->assertTrue($d->json('agent.active'));

        $this->putJson("/api/admin/agents/{$agent->id}", ['full_name' => 'Awa N.', 'zone' => 'Bacongo', 'city' => 'Brazzaville', 'country' => 'CG'])->assertOk();
        $this->assertSame('Bacongo', $agent->fresh()->zone);
        $this->assertSame('Awa N.', $agent->user->fresh()->full_name);

        $this->postJson("/api/admin/accounts/{$agent->user_id}/status", ['active' => false])->assertOk();
        $this->assertFalse($this->getJson('/api/admin/agents')->json('data.0.active'));
    }
}

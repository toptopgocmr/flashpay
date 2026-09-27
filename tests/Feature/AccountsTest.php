<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    protected function admin(): User
    {
        $a = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']);
        $a->assignRole('super_admin');
        return $a;
    }

    public function test_admin_creates_merchant_and_agent_who_can_login(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/admin/merchants', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'Jean Kaba', 'phone' => '065551111', 'password' => 'secret123', 'business_name' => 'Boutique Jean'])
            ->assertCreated()->assertJsonPath('login.phone', '242065551111');
        $this->assertSame(1, count($this->getJson('/api/admin/merchants?country=CG&city=Brazzaville')->json('data')));
        $this->assertSame(0, count($this->getJson('/api/admin/merchants?city=Pointe-Noire')->json('data')));
        $this->assertContains('Pointe-Noire', collect($this->getJson('/api/admin/geo')->json('countries'))->firstWhere('country', 'CG')['cities']);
        $this->postJson('/api/admin/agents', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'Awa Agent', 'phone' => '+242055552222', 'password' => 'secret123', 'zone' => 'Poto-Poto'])
            ->assertCreated();
        $this->postJson('/api/admin/agents', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'Awa Agent', 'phone' => '242055552222'])->assertStatus(422);
        $this->postJson('/api/admin/merchants', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'X', 'phone' => '066660000', 'business_name' => 'Y'])->assertStatus(422); // mot de passe requis

        $this->app['auth']->forgetGuards();
        $m = $this->postJson('/api/auth/login', ['phone' => '242065551111', 'password' => 'secret123', 'profile' => 'merchant'])->assertOk();
        $this->assertSame('approved', $m->json('user.merchant.validation_status'));
        $a = $this->postJson('/api/auth/login', ['phone' => '242055552222', 'password' => 'secret123', 'profile' => 'agent'])->assertOk();
        $this->assertContains('agent', $a->json('user.roles'));

        $agentUser = User::where('phone', '242055552222')->first();
        $this->actingAs($agentUser, 'sanctum');
        $this->getJson('/api/agent/dashboard')->assertOk()->assertJsonPath('float', 0)->assertJsonPath('currency', 'XAF');
        $this->getJson('/api/agent/history')->assertOk();
    }

    public function test_existing_client_gets_merchant_profile_and_password_reset(): void
    {
        $c = User::create(['full_name' => 'Cli', 'phone' => '242061234567', 'password' => bcrypt('old-pass')]);
        $c->assignRole('client');
        Wallet::create(['user_id' => $c->id, 'balance' => 100, 'currency' => 'XAF', 'country' => 'CG']);

        $this->actingAs($this->admin(), 'sanctum');
        $r = $this->postJson('/api/admin/merchants', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'Cli', 'phone' => '061234567', 'business_name' => 'Cli Shop'])->assertCreated();
        $this->assertFalse($r->json('login.new_account'));
        $this->assertTrue($c->fresh()->hasRole('merchant'));
        $this->assertSame(1, Wallet::where('user_id', $c->id)->count());

        $this->postJson("/api/admin/users/{$c->id}/password", ['password' => 'nouveau123'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['phone' => '242061234567', 'password' => 'nouveau123', 'profile' => 'merchant'])->assertOk();
    }

    public function test_fund_agent_then_cash_in(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/admin/agents', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'Awa', 'phone' => '055552222', 'password' => 'secret123'])->assertCreated();
        $agentUser = User::where('phone', '242055552222')->first();
        $this->postJson("/api/admin/agents/{$agentUser->agent->id}/float", ['amount' => 200000])->assertCreated()->assertJsonPath('float', 200000);
        $this->assertSame(1, $this->getJson('/api/admin/transactions?channel=agent_float')->json('total'));

        $c = User::create(['full_name' => 'Cli', 'phone' => '242061234567', 'password' => 'x']);
        $c->assignRole('client');
        Wallet::create(['user_id' => $c->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        $this->actingAs($agentUser->fresh(), 'sanctum');
        $this->postJson('/api/agent/cash-in', ['client_phone' => '061234567', 'amount' => 15000])->assertStatus(201);
        $this->actingAs($agentUser->fresh(), 'sanctum'); // nouvelle requête = utilisateur rechargé
        $d = $this->getJson('/api/agent/dashboard')->assertOk();
        $this->assertSame(185100, $d->json('float')); // + commission cash-in 100 (barème §3.1.7)
        $this->assertSame(100, $d->json('commission_month'));
        $this->assertSame(1, $d->json('today.cash_in_count'));
        $this->assertSame('Dépôt', $this->getJson('/api/agent/history')->json('data.0.label'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashNetworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    protected function mk(string $role, string $phone, int $balance): User
    {
        $u = User::create(['full_name' => "Test {$role} Dupont", 'phone' => $phone, 'password' => 'secret123']);
        $u->assignRole($role);
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        if ($role === 'merchant') {
            Merchant::create(['user_id' => $u->id, 'business_name' => 'Boutique Test', 'country' => 'CG', 'qr_code_token' => 'FPM-TEST', 'validation_status' => 'approved']);
        }
        if ($role === 'agent') {
            Agent::create(['user_id' => $u->id, 'validation_status' => 'approved', 'validated_at' => now()]);
        }
        return $u->fresh();
    }

    public function test_methods_by_country(): void
    {
        $c = $this->mk('client', '242061111111', 0);
        $this->actingAs($c, "sanctum");

        $r = $this->getJson('/api/pay/methods?country=CG&operation=withdraw')->assertOk();
        $keys = collect($r->json('methods'))->pluck('available', 'key')->all();
        $this->assertTrue($keys['mobile_money']);
        $this->assertTrue($keys['cash_pickup']);
        $this->assertTrue($keys['atm']);
        $mm = collect($r->json('methods'))->firstWhere('key', 'mobile_money');
        $this->assertContains('Airtel Money', array_column($mm['operators'], 'label'));

        $r = $this->getJson('/api/pay/methods?country=SN&operation=withdraw')->assertOk();
        $keys = collect($r->json('methods'))->pluck('available', 'key')->all();
        $this->assertFalse($keys['cash_pickup']);
        $this->assertContains('Orange Money', array_column(collect($r->json('methods'))->firstWhere('key', 'mobile_money')['operators'], 'label'));

        $this->getJson('/api/pay/methods?country=CG&operation=pay')->assertOk()->assertJsonPath('methods.0.key', 'scan_qr');
    }

    public function test_pay_code_merchant_charge_is_single_use(): void
    {
        $client = $this->mk('client', '242061111111', 50000);
        $merchant = $this->mk('merchant', '242062222222', 0);

        $this->actingAs($client, "sanctum");
        $code = $this->postJson('/api/pay/code')->assertCreated()->json('code');
        $this->assertSame(18, strlen($code));

        $this->actingAs($merchant, "sanctum");
        $this->postJson('/api/merchant/charge-code', ['code' => $code, 'amount' => 15000])->assertStatus(201);
        $this->postJson('/api/merchant/charge-code', ['code' => $code, 'amount' => 15000])->assertStatus(422);

        $this->assertSame(35000, $client->wallet->fresh()->balance);
        $this->assertSame(14850, $merchant->wallet->fresh()->balance); // commission marchand 1 %
    }

    public function test_agent_cash_in_with_client_qr(): void
    {
        $client = $this->mk('client', '242061111111', 0);
        $agent = $this->mk('agent', '242063333333', 100000);

        $this->actingAs($client, "sanctum");
        $code = $this->postJson('/api/pay/code')->json('code');

        $this->actingAs($agent, "sanctum");
        $this->postJson('/api/agent/cash-in', ['client_code' => $code, 'amount' => 20000])->assertStatus(201);
        $this->assertSame(20000, $client->wallet->fresh()->balance);
        $this->assertSame(80100, $agent->wallet->fresh()->balance); // float − dépôt + commission 100
    }

    public function test_cash_pickup_voucher_redeem(): void
    {
        $client = $this->mk('client', '242061111111', 50000);
        $agent = $this->mk('agent', '242063333333', 0);

        $this->actingAs($client, "sanctum");
        $r = $this->postJson('/api/pay/vouchers', ['channel' => 'cash_pickup', 'amount' => 10000, 'country' => 'CG'])->assertCreated();
        $code = $r->json('code');
        $this->assertSame(10, strlen($code));
        $this->assertSame(100, $r->json('fee'));
        $this->assertSame(39900, $client->wallet->fresh()->balance);

        $this->actingAs($agent, "sanctum");
        $this->getJson("/api/agent/vouchers/{$code}")->assertOk()->assertJsonPath('amount', 10000)->assertJsonPath('code', null);
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertOk();
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertStatus(422);
        $this->assertSame(10050, $agent->wallet->fresh()->balance); // montant + 50 % des frais

        // Compte d'attente des bons soldé
        $bal = LedgerEntry::where('account', 'flashpay:vouchers')->get()->sum(fn ($e) => $e->type === 'credit' ? $e->amount : -$e->amount);
        $this->assertSame(0, (int) $bal);
    }

    public function test_voucher_cancel_and_expiry_refund(): void
    {
        $client = $this->mk('client', '242061111111', 50000);
        $this->actingAs($client, "sanctum");

        $id = $this->postJson('/api/pay/vouchers', ['channel' => 'cash_pickup', 'amount' => 10000, 'country' => 'CG'])->json('id');
        $this->postJson("/api/pay/vouchers/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(50000, $client->wallet->fresh()->balance);

        $this->postJson('/api/pay/vouchers', ['channel' => 'cash_pickup', 'amount' => 5000, 'country' => 'CG']);
        $this->travel(4)->days();
        $this->assertSame(0, Artisan::call('vouchers:expire'));
        $this->assertSame(50000, $client->wallet->fresh()->balance);
    }

    public function test_atm_partner_redeem(): void
    {
        $client = $this->mk('client', '242061111111', 50000);
        $this->actingAs($client, "sanctum");
        $code = $this->postJson('/api/pay/vouchers', ['channel' => 'atm', 'amount' => 20000, 'country' => 'CG'])->assertCreated()->json('code');
        $this->assertSame(12, strlen($code));

        $this->postJson('/api/partners/atm/redeem', ['code' => $code, 'atm_ref' => 'GAB-1'])->assertStatus(401);
        $h = ['X-Partner-Key' => 'bank-secret'];
        $this->postJson('/api/partners/atm/verify', ['code' => $code], $h)->assertOk()->assertJsonPath('amount', 20000);
        $this->postJson('/api/partners/atm/redeem', ['code' => $code, 'atm_ref' => 'GAB-1'], $h)->assertOk();
        $this->postJson('/api/partners/atm/redeem', ['code' => $code, 'atm_ref' => 'GAB-1'], $h)->assertStatus(422);
    }

    public function test_cash_pickup_refused_where_no_agents(): void
    {
        $client = $this->mk('client', '242061111111', 50000);
        $this->actingAs($client, "sanctum");
        $this->postJson('/api/pay/vouchers', ['channel' => 'cash_pickup', 'amount' => 5000, 'country' => 'CM'])->assertStatus(422);
    }
}

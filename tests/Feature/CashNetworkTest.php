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
        $this->getJson("/api/agent/vouchers/{$code}")->assertOk()->assertJsonPath('amount', 10000)->assertJsonPath('code', null)
            ->assertJsonPath('third_party', false);
        // Sans l'accord de l'expéditeur : pas de paiement
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertStatus(409);
        $this->postJson('/api/agent/vouchers/confirm-request', ['code' => $code])->assertStatus(202)->assertJsonPath('confirmation_status', 'awaiting_sender');
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertStatus(409);

        $this->actingAs($client, "sanctum");
        $vid = $this->getJson('/api/pay/vouchers')->assertOk()->assertJsonPath('0.confirmation_status', 'awaiting_sender')->json('0.id');
        $this->postJson("/api/pay/vouchers/{$vid}/confirm", ['approve' => true])->assertOk()->assertJsonPath('confirmation_status', 'approved');

        $this->actingAs($agent, "sanctum");
        $this->getJson("/api/agent/vouchers/{$code}")->assertJsonPath('confirmation_status', 'approved');
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertOk();
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertStatus(422);
        $this->assertSame(10050, $agent->wallet->fresh()->balance); // montant + 50 % des frais

        // Compte d'attente des bons soldé
        $bal = LedgerEntry::where('account', 'flashpay:vouchers')->get()->sum(fn ($e) => $e->type === 'credit' ? $e->amount : -$e->amount);
        $this->assertSame(0, (int) $bal);
    }

    public function test_third_party_withdrawal_needs_name_phone_and_sender_approval(): void
    {
        $client = $this->mk('client', '242061111111', 50000);
        $agent = $this->mk('agent', '242063333333', 0);
        $other = $this->mk('agent', '242064444444', 0);

        $this->actingAs($client, "sanctum");
        $code = $this->postJson('/api/pay/vouchers', ['channel' => 'cash_pickup', 'amount' => 10000, 'country' => 'CG',
            'beneficiary_name' => 'Kloé Makaya', 'beneficiary_phone' => '+242 06 555 44 33'])->assertCreated()->json('code');

        $this->actingAs($agent, "sanctum");
        // L'agent ne voit pas le bénéficiaire : il le saisit depuis la pièce
        $this->getJson("/api/agent/vouchers/{$code}")->assertJsonPath('third_party', true)->assertJsonPath('beneficiary_name', null);
        $this->postJson('/api/agent/vouchers/confirm-request', ['code' => $code])->assertStatus(422);
        $this->postJson('/api/agent/vouchers/confirm-request', ['code' => $code, 'beneficiary_name' => 'Paul Makaya', 'beneficiary_phone' => '065554433'])->assertStatus(422);
        $this->postJson('/api/agent/vouchers/confirm-request', ['code' => $code, 'beneficiary_name' => 'Makaya Kloe', 'beneficiary_phone' => '060000000'])->assertStatus(422);
        $this->postJson('/api/agent/vouchers/confirm-request', ['code' => $code, 'beneficiary_name' => 'Makaya Kloe', 'beneficiary_phone' => '+242065554433'])->assertStatus(202);

        // Refus de l'expéditeur : l'agent ne peut pas payer
        $this->actingAs($client, "sanctum");
        $vid = $this->getJson('/api/pay/vouchers')->json('0.id');
        $this->postJson("/api/pay/vouchers/{$vid}/confirm", ['approve' => false])->assertOk()->assertJsonPath('confirmation_status', 'rejected');
        $this->actingAs($agent, "sanctum");
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertStatus(409);

        // Nouvelle demande, accord : seul l'agent qui a demandé peut payer
        $this->postJson('/api/agent/vouchers/confirm-request', ['code' => $code, 'beneficiary_name' => 'Kloe Makaya', 'beneficiary_phone' => '065554433'])->assertStatus(202);
        $this->actingAs($client, "sanctum");
        $this->postJson("/api/pay/vouchers/{$vid}/confirm", ['approve' => true])->assertOk();
        $this->actingAs($other, "sanctum");
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertStatus(409);
        $this->actingAs($agent, "sanctum");
        $this->postJson('/api/agent/vouchers/redeem', ['code' => $code])->assertOk()->assertJsonPath('beneficiary_name', 'Kloé Makaya');
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

    public function test_atm_amount_rules(): void
    {
        $c = $this->mk('client', '242061111111', 500000);
        $this->actingAs($c, 'sanctum');
        $atm = collect($this->getJson('/api/pay/methods?country=CG&operation=withdraw')->json('methods'))->firstWhere('key', 'atm');
        $this->assertSame(['min' => 10000, 'max' => 250000, 'step' => 5000], $atm['limits']);
        $msg = 'Saisissez un montant entre 10 000 et 250 000 FCFA, par tranches de 5 000.';
        $this->postJson('/api/pay/vouchers', ['channel' => 'atm', 'amount' => 5000, 'country' => 'CG'])->assertStatus(422)->assertJsonFragment(['message' => $msg]);
        $this->postJson('/api/pay/vouchers', ['channel' => 'atm', 'amount' => 12500, 'country' => 'CG'])->assertStatus(422)->assertJsonFragment(['message' => $msg]);
        $this->postJson('/api/pay/vouchers', ['channel' => 'atm', 'amount' => 300000, 'country' => 'CG'])->assertStatus(422);
        $this->postJson('/api/pay/vouchers', ['channel' => 'atm', 'amount' => 15000, 'country' => 'CG'])->assertCreated();
    }

    public function test_locate_agents_agencies_and_atms(): void
    {
        $agent = $this->mk('agent', '242063333333', 0);
        $hidden = $this->mk('agent', '242064444444', 0); // sans position : absent de la carte

        // L'agent enregistre la position de son point de service (Ouenzé)
        $this->actingAs($agent, 'sanctum');
        $this->postJson('/api/agent/location', ['lat' => -4.2380, 'lng' => 15.2660, 'address' => 'Rue Itoumbi, Ouenzé', 'opening_hours' => '7h-20h'])->assertOk();

        // Console : un GAB et une agence
        $admin = User::create(['full_name' => 'Admin', 'phone' => '242069999999', 'password' => 'secret123']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/admin/service-points', ['type' => 'atm', 'name' => 'GAB BGFI Poto-Poto', 'operator' => 'BGFIBank', 'city' => 'Brazzaville',
            'latitude' => -4.2700, 'longitude' => 15.2830])->assertCreated();
        $this->postJson('/api/admin/service-points', ['type' => 'agency', 'name' => 'Agence OfficePay Plateau', 'city' => 'Brazzaville',
            'latitude' => -4.2690, 'longitude' => 15.2850, 'opening_hours' => 'Lun-Sam 8h-17h'])->assertCreated();
        $this->assertCount(2, $this->getJson('/api/admin/service-points')->assertOk()->json('points'));

        // Client (même non connecté) près de Ouenzé : le plus proche d'abord
        $this->app['auth']->forgetGuards();
        $r = $this->getJson('/api/locations?lat=-4.2390&lng=15.2650')->assertOk();
        $list = $r->json('locations');
        $this->assertCount(3, $list);
        $this->assertSame('agent', $list[0]['type']);
        $this->assertSame('Rue Itoumbi, Ouenzé', $list[0]['address']);
        $this->assertLessThan(1, $list[0]['distance_km']);
        $this->assertStringNotContainsString((string) $hidden->phone, json_encode($list));

        // Filtre « GAB » : logo de la banque, ouvert 24h/24
        $atms = $this->getJson('/api/locations?lat=-4.2390&lng=15.2650&types=atm')->assertOk()->json('locations');
        $this->assertCount(1, $atms);
        $this->assertSame('24h/24', $atms[0]['opening_hours']);
        $this->assertStringContainsString('bgfi.com', (string) $atms[0]['logo']);

        // Recherche par nom ; rayon
        $this->assertSame('Agence OfficePay Plateau', $this->getJson('/api/locations?q=plateau')->json('locations.0.name'));
        $this->assertCount(0, $this->getJson('/api/locations?lat=-4.80&lng=11.86&radius=20')->json('locations')); // Pointe-Noire
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

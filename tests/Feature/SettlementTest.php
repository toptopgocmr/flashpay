<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    protected function merchant(int $balance): User
    {
        $u = User::create(['full_name' => 'Jean Kaba', 'phone' => '242065551111', 'password' => 'x']);
        $u->assignRole('merchant');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        Merchant::create(['user_id' => $u->id, 'business_name' => 'Boutique Jean', 'country' => 'CG', 'city' => 'Brazzaville', 'qr_code_token' => 'FPM-J', 'validation_status' => 'approved', 'settlement_phone' => '242065551111']);
        return $u->fresh();
    }

    public function test_accounts_bank_wallet_cash_settlements(): void
    {
        $m = $this->merchant(100000);
        $friend = User::create(['full_name' => 'Perso Jean', 'phone' => '242069990000', 'password' => 'x']);
        $friend->assignRole('client');
        Wallet::create(['user_id' => $friend->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);

        $this->actingAs($m, 'sanctum');
        $s = $this->getJson('/api/merchant/settlement')->assertOk();
        $this->assertSame('mobile_money', $s->json('accounts.0.type')); // créé automatiquement
        $this->assertTrue($s->json('accounts.0.is_default'));
        $this->assertSame('MTN Mobile Money', $s->json('accounts.0.operator'));

        $bank = $this->postJson('/api/merchant/settlement/accounts', ['type' => 'bank', 'bank_name' => 'BGFI Bank Congo', 'account_holder' => 'Boutique Jean', 'account_number' => 'CG39 3001 1000 0101 2345 6789 012'])->assertCreated()->json();
        $wallet = $this->postJson('/api/merchant/settlement/accounts', ['type' => 'wallet', 'phone' => '069990000'])->assertCreated()->json();
        $cash = $this->postJson('/api/merchant/settlement/accounts', ['type' => 'cash_pickup'])->assertCreated()->json();
        $this->postJson('/api/merchant/settlement/accounts', ['type' => 'wallet', 'phone' => '061111111'])->assertStatus(422);
        $this->postJson('/api/merchant/settlement/accounts', ['type' => 'bank', 'bank_name' => 'X'])->assertStatus(422);

        // Wallet FlashPay : instantané
        $this->postJson('/api/merchant/settlement/settle', ['account_id' => $wallet['id'], 'amount' => 20000])->assertStatus(201);
        $this->assertSame(20000, $friend->wallet->fresh()->balance);

        // Banque : en attente de l'équipe FlashPay (frais 0,5 %)
        $b = $this->postJson('/api/merchant/settlement/settle', ['account_id' => $bank['id'], 'amount' => 50000])->assertStatus(202)->json();
        $this->assertSame(250, $b['fee']);
        $this->assertSame(100000 - 20200 - 50250, Wallet::where('user_id', $m->id)->value('balance'));

        // Cash : code de retrait
        $c = $this->postJson('/api/merchant/settlement/settle', ['account_id' => $cash['id'], 'amount' => 5000])->assertStatus(202)->json();
        $this->assertSame(10, strlen($c['code']));

        $this->assertCount(3, $this->getJson('/api/merchant/settlement')->json('history'));

        // Console : rejet -> remboursement ; exécution -> réussi
        $admin = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum');
        $q = $this->getJson('/api/admin/settlements/bank')->assertOk();
        $this->assertSame(50000, $q->json('pending_total'));
        $before = Wallet::where('user_id', $m->id)->value('balance');
        $this->postJson("/api/admin/settlements/{$b['id']}/reject", ['reason' => 'RIB erroné'])->assertOk();
        $this->assertSame($before + 50250, Wallet::where('user_id', $m->id)->value('balance'));
        $this->postJson("/api/admin/settlements/{$b['id']}/complete", ['bank_ref' => 'VIR-1'])->assertStatus(422);

        $this->actingAs($m->fresh(), 'sanctum');
        $b2 = $this->postJson('/api/merchant/settlement/settle', ['account_id' => $bank['id'], 'amount' => 10000])->json();
        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/admin/settlements/{$b2['id']}/complete", ['bank_ref' => 'VIR-2'])->assertOk();
        $this->assertSame('successful', Transaction::find($b2['id'])->status);
        $this->assertSame(1, $this->getJson('/api/admin/transactions?channel=bank_transfer&status=successful')->json('total'));
    }

    public function test_auto_settlement_to_default_wallet_account(): void
    {
        $m = $this->merchant(80000);
        $friend = User::create(['full_name' => 'Perso', 'phone' => '242069990000', 'password' => 'x']);
        $friend->assignRole('client');
        Wallet::create(['user_id' => $friend->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);

        $this->actingAs($m, 'sanctum');
        $w = $this->postJson('/api/merchant/settlement/accounts', ['type' => 'wallet', 'phone' => '+242069990000', 'is_default' => true])->json();
        $this->postJson('/api/merchant/settlement/auto', ['mode' => 'daily', 'min' => 10000])->assertOk();

        $this->assertSame(0, Artisan::call('merchants:settle'));
        $sent = $friend->wallet->fresh()->balance; // 70 000 disponibles, frais de 1 % inclus
        $this->assertGreaterThanOrEqual(69300, $sent);
        $this->assertLessThanOrEqual(10001, Wallet::where('user_id', $m->id)->value('balance'));
        $this->assertGreaterThanOrEqual(10000, Wallet::where('user_id', $m->id)->value('balance'));
        Artisan::call('merchants:settle'); // déjà réglé aujourd'hui
        $this->assertSame($sent, $friend->wallet->fresh()->balance);
    }

    public function test_admin_creates_merchant_with_bank_settlement(): void
    {
        $admin = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/admin/merchants', [
            'full_name' => 'Awa', 'phone' => '055550000', 'password' => 'secret123', 'business_name' => 'Chez Awa', 'country' => 'CG', 'city' => 'Pointe-Noire',
            'settlement' => ['type' => 'bank', 'bank_name' => 'LCB', 'account_holder' => 'Chez Awa', 'account_number' => '30011 00001 12345678901 55'],
        ])->assertCreated();
        $mm = Merchant::where('business_name', 'Chez Awa')->first();
        $this->assertSame('bank', $mm->settlementAccounts()->first()->type);
        // Compte bancaire incomplet : rien n'est créé
        $this->postJson('/api/admin/merchants', ['full_name' => 'B', 'phone' => '055550001', 'password' => 'secret123', 'business_name' => 'B', 'country' => 'CG', 'city' => 'Dolisie', 'settlement' => ['type' => 'bank']])->assertStatus(422);
        $this->assertNull(User::where('phone', '242055550001')->first());
    }
}

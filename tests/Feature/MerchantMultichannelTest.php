<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Modes de retrait multicanal des marchands. */
class MerchantMultichannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $a = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']);
        $a->assignRole('super_admin');
        $this->actingAs($a, 'sanctum');
    }

    public function test_create_merchant_with_several_withdrawal_channels_and_manage_them(): void
    {
        $this->postJson('/api/admin/merchants', [
            'country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'Jean Kaba', 'phone' => '065551111', 'password' => 'secret123', 'business_name' => 'Boutique Jean',
            'settlements' => [
                ['type' => 'mobile_money'],
                ['type' => 'bank', 'bank_name' => 'BGFI Bank Congo', 'account_number' => 'CG39 3001 1000', 'is_default' => true],
                ['type' => 'cash_pickup'],
            ],
        ])->assertCreated();

        $m = Merchant::first();
        $accs = $m->settlementAccounts()->get();
        $this->assertSame(['bank', 'cash_pickup', 'mobile_money'], $accs->pluck('type')->sort()->values()->all());
        $this->assertSame('bank', $accs->firstWhere('is_default', true)->type);
        $this->assertSame('Boutique Jean', $accs->firstWhere('type', 'bank')->account_holder);
        $this->assertCount(3, $this->getJson('/api/admin/merchants')->json('data.0.channels'));

        // Gestion par le Super Admin
        $this->postJson("/api/admin/merchants/{$m->id}/accounts", ['type' => 'wallet'])->assertCreated();
        $list = $this->getJson("/api/admin/merchants/{$m->id}/accounts")->assertOk()->json();
        $this->assertCount(4, $list);
        $mm = collect($list)->firstWhere('type', 'mobile_money');
        $this->postJson("/api/admin/merchants/{$m->id}/accounts/{$mm['id']}/default")->assertOk();
        $this->assertTrue((bool) $m->settlementAccounts()->find($mm['id'])->is_default);
        $this->deleteJson("/api/admin/merchants/{$m->id}/accounts/{$mm['id']}")->assertOk();
        $this->assertSame(1, $m->settlementAccounts()->where('is_default', true)->count()); // un autre défaut repris
        foreach ($m->settlementAccounts()->get()->slice(1) as $a) {
            $this->deleteJson("/api/admin/merchants/{$m->id}/accounts/{$a->id}")->assertOk();
        }
        $last = $m->settlementAccounts()->first();
        $this->deleteJson("/api/admin/merchants/{$m->id}/accounts/{$last->id}")->assertStatus(422);
    }

    public function test_legacy_single_settlement_still_works(): void
    {
        $this->postJson('/api/admin/merchants', ['country' => 'CG', 'city' => 'Brazzaville', 'full_name' => 'A', 'phone' => '066660000', 'password' => 'secret123', 'business_name' => 'B'])->assertCreated();
        $this->assertSame('mobile_money', Merchant::first()->settlementAccounts()->first()->type);
    }
}

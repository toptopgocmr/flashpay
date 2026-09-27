<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    public function test_dashboard_families_and_filter(): void
    {
        $admin = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $c = User::create(['full_name' => 'Cli Ent', 'phone' => '242061111111', 'password' => 'x']);
        $c->assignRole('client');
        Wallet::create(['user_id' => $c->id, 'balance' => 50000, 'currency' => 'XAF', 'country' => 'CG']);

        $this->actingAs($c, 'sanctum');
        $this->postJson('/api/pay/vouchers', ['channel' => 'cash_pickup', 'amount' => 1000, 'country' => 'CG'])->assertCreated();

        $this->actingAs($admin, 'sanctum');
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('families');
        $w = collect($d)->firstWhere('key', 'withdrawals');
        $this->assertSame(1, $w['count']);
        $this->assertSame(1, collect($w['channels'])->firstWhere('key', 'withdrawal_qr')['count']);

        $r = $this->getJson('/api/admin/transactions?channel=withdrawals')->assertOk();
        $this->assertSame(1, $r->json('total'));
        $this->assertSame('QR code / bon de retrait (agent, GAB)', $r->json('data.0.channel_label'));
        $this->assertSame(0, $this->getJson('/api/admin/transactions?channel=payments')->json('total'));
        $this->assertSame(1, $this->getJson('/api/admin/transactions?status=processing&days=7')->json('total'));
    }
}

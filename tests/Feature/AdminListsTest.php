<?php
namespace Tests\Feature;
use App\Models\{User, Wallet, Merchant, Agent};
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
class AdminListsTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]); }
    public function test_lists(): void
    {
        $admin = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']); $admin->assignRole('super_admin');
        $m = User::create(['full_name' => 'Jean Boutique', 'phone' => '242061111111', 'password' => 'x']);
        Wallet::create(['user_id' => $m->id, 'balance' => 700, 'currency' => 'XAF', 'country' => 'CG']);
        Merchant::create(['user_id' => $m->id, 'business_name' => 'Chez Jean', 'qr_code_token' => 'FPM-X', 'validation_status' => 'pending']);
        Agent::create(['user_id' => $m->id, 'validation_status' => 'approved']);
        $this->actingAs($admin, 'sanctum');
        $r = $this->getJson('/api/admin/merchants')->assertOk();
        $this->assertSame('Chez Jean', $r->json('data.0.business_name'));
        $this->assertSame(700, $r->json('data.0.balance'));
        $this->assertSame(1, $r->json('counts.pending'));
        $this->assertSame(0, count($this->getJson('/api/admin/merchants?status=approved')->json('data')));
        $this->assertSame(1, count($this->getJson('/api/admin/merchants?q=Jean')->json('data')));
        $this->assertSame(1, count($this->getJson('/api/admin/agents?status=approved')->json('data')));
        $this->getJson('/api/admin/badges')->assertOk()->assertJson(['merchants' => 1, 'agents' => 0]);
    }
}

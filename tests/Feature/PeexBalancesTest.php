<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PeexBalancesTest extends TestCase
{
    public function test_super_admin_sees_the_three_peex_balances(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.peex.secret_key' => 'k']);
        Http::fake(function (HttpRequest $r) {
            $p = parse_url($r->url(), PHP_URL_PATH);
            return match (true) {
                str_ends_with($p, 'clients/me') => Http::response(['solde' => 2000, 'is_activated' => true]),
                str_ends_with($p, 'disbursement/me') => Http::response(['disbursement_solde' => 150000, 'is_activated' => true, 'mtn_fees' => 1]),
                str_ends_with($p, 'collection/me') => Http::failedConnection(),
                default => Http::response([]),
            };
        });

        $this->actingAs(User::where('phone', '242060000000')->first(), 'sanctum');
        $r = $this->getJson('/api/admin/peex/balances?refresh=1')->assertOk();
        $this->assertEquals(2000, $r->json('accounts.remittance.balance'));
        $this->assertTrue($r->json('accounts.remittance.low'));
        $this->assertEquals(150000, $r->json('accounts.disbursement.available'));
        $this->assertFalse($r->json('accounts.collect.ok'));
        $this->assertEquals(152000, $r->json('total'));
    }
}

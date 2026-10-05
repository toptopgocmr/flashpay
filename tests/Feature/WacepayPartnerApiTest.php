<?php

namespace Tests\Feature;

use App\Models\DigitwaceRequest;
use App\Models\User;
use App\Services\Digitwace\DigitwaceConnector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** API Partenaire WacePay (collection Postman « Wacepay Partner API »). */
class WacepayPartnerApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.rails.digitwace.enabled' => true, 'flashpay.digitwace.enabled' => true, 'flashpay.digitwace.api' => 'partner',
            'flashpay.digitwace.public_key' => 'pub', 'flashpay.digitwace.private_key' => 'priv',
            'flashpay.digitwace.base_url' => 'https://sandbox.wace.test/api/v1/',
        ]);
    }

    public function test_partner_api_flow(): void
    {
        $sent = [];
        Http::fake(function ($req) use (&$sent) {
            $u = $req->url();
            $sent[] = $req;
            return match (true) {
                str_ends_with($u, 'payments/get-token') => $req->header('Authorization')[0] === 'Basic ' . base64_encode('pub:priv')
                    ? Http::response(['status' => true, 'message' => 'ok', 'token' => 'TOK', 'expiresAt' => now()->addHour()->toIso8601String()])
                    : Http::response(['message' => 'bad'], 401),
                str_ends_with($u, 'payments/services') => Http::response(['status' => true, 'data' => [
                    ['id' => 'svc-in-sn', 'name' => 'Orange Money Sénégal', 'countryCode' => 'SN', 'currency' => 'XOF', 'operator' => 'ORANGE'],
                ]]),
                str_ends_with($u, 'payout/services') => Http::response(['status' => true, 'data' => [
                    ['id' => 'svc-out-sn', 'name' => 'Wave Sénégal', 'countryCode' => 'SN', 'currency' => 'XOF'],
                ]]),
                str_ends_with($u, 'payments/create') => Http::response(['status' => true, 'data' => ['referenceId' => $req['referenceId'], 'trans_code' => 'TC1', 'status' => 'PENDING']]),
                str_ends_with($u, 'payments/check-status') => Http::response(['status' => true, 'data' => ['status' => 'SUCCESS', 'amount' => 5000, 'netAmount' => 4900]]),
                str_ends_with($u, 'payout/execute') => Http::response(['status' => true, 'data' => ['id' => 'PO-9', 'status' => 'PROCESSING']]),
                str_contains($u, 'payout/refresh-status/PO-9') => Http::response(['status' => true, 'data' => ['id' => 'PO-9', 'status' => 'SUCCESS']]),
                str_contains($u, 'payout/balance/history') => Http::response(['status' => true, 'data' => ['items' => [
                    ['currency' => 'XOF', 'amount' => -1000, 'balanceAfter' => 249000],
                    ['currency' => 'XOF', 'amount' => 250000, 'balanceAfter' => 250000],
                ]]]),
                default => Http::response(['message' => 'not found'], 404),
            };
        });

        $admin = User::create(['full_name' => 'Super', 'phone' => '242069990077', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors-sync/wacepay')->assertOk();

        $c = app(DigitwaceConnector::class);

        // Collecte : POST payments/create avec wp-subscription-key
        $r = $c->collect('221771234567', 5000, 'XOF', 'FP-IN-1');
        $this->assertSame('pending', $r['status']);
        $create = collect($sent)->first(fn ($q) => str_ends_with($q->url(), 'payments/create'));
        $this->assertSame('svc-in-sn', $create->header('wp-subscription-key')[0]);
        $this->assertSame('Bearer TOK', $create->header('Authorization')[0]);
        $this->assertSame('FP-IN-1-C', $create['referenceId']);
        $this->assertSame('+221771234567', $create['customer_msisdn']);
        $this->assertSame('ORANGE', $create['operator']);
        $this->assertSame('SN', $create['countryCode']);
        $this->assertSame(5000, $create['amount']);

        // Statut de collecte : referenceId en en-tête
        $s = $c->checkStatus('FP-IN-1-C');
        $this->assertSame('successful', $s['status']);
        $chk = collect($sent)->first(fn ($q) => str_ends_with($q->url(), 'payments/check-status'));
        $this->assertSame('FP-IN-1-C', $chk->header('referenceId')[0]);

        // Versement : POST payout/execute
        $p = $c->disburse('221771234567', 1000, 'XOF', 'FP-OUT-1');
        $this->assertSame('PO-9', $p['external_ref']);
        $exec = collect($sent)->first(fn ($q) => str_ends_with($q->url(), 'payout/execute'));
        $this->assertSame('svc-out-sn', $exec['payoutSubscriptionId']);
        $this->assertSame('+221771234567', $exec['recipientMsisdn']);
        $this->assertStringEndsWith('/api/webhooks/digitwace', $exec['callbackUrl']);
        $this->assertFalse(collect($sent)->contains(fn ($q) => str_contains($q->url(), 'sender/create')));
        $this->assertSame('PO-9', DigitwaceRequest::where('reference', 'FP-OUT-1')->value('wace_id'));
        $this->assertSame('successful', $c->checkStatus('PO-9')['status']);

        // Solde : dernier mouvement de payout/balance/history
        $b = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/digitwace/balances?refresh=1')->assertOk()->json();
        $this->assertTrue($b['ok']);
        $this->assertEquals(249000, $b['accounts'][0]['balance']);
        $this->assertSame('XOF', $b['accounts'][0]['currency']);
    }
}

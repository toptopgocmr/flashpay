<?php

namespace Tests\Feature;

use App\Models\DigitwaceRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WacepayCardBankTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.digitwace.enabled' => true, 'flashpay.digitwace.api' => 'legacy', 'flashpay.digitwace.public_key' => 'pub', 'flashpay.digitwace.private_key' => 'priv',
            'flashpay.digitwace.base_url' => 'https://wace.test/api/v1/', 'flashpay.rails.digitwace.enabled' => true,
            'payment_methods.card_driver' => 'wacepay', 'payment_methods.bank_debit_driver' => 'wacepay',
        ]);
    }

    protected function client(): User
    {
        $u = User::create(['full_name' => 'ALICE MBEMBA', 'phone' => '242061111111', 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        return $u->fresh('wallet');
    }

    protected function fake(string &$status, array &$sent): void
    {
        Http::fake(function ($req) use (&$status, &$sent) {
            $sent[] = [$req->url(), $req->data()];
            return match (true) {
                str_contains($req->url(), 'get-token') => $req->method() === 'GET' && $req->header('Authorization')[0] === 'Basic ' . base64_encode('pub:priv') ? Http::response(['data' => ['token' => 'tok-123']], 200) : Http::response(['message' => 'bad auth'], 401),
                str_contains($req->url(), 'payin/card'), str_contains($req->url(), 'payin/bank') => Http::response(['data' => ['transactionCode' => 'WC-' . count($sent), 'status' => 'PENDING', 'paymentUrl' => 'https://pay.wace.test/c/' . count($sent)]], 200),
                str_contains($req->url(), 'transaction/status') => Http::response(['data' => ['status' => $status, 'maskedPan' => '424242******4242']], 200),
                default => Http::response([], 404),
            };
        });
    }

    public function test_card_and_bank_deposits_via_wacepay(): void
    {
        $status = 'PENDING';
        $sent = [];
        $this->fake($status, $sent);
        $u = $this->client();

        // Méthodes : carte et compte bancaire disponibles
        $m = $this->actingAs($u, 'sanctum')->getJson('/api/pay/methods?country=CG&operation=deposit')->assertOk()->json('methods');
        $this->assertTrue(collect($m)->firstWhere('key', 'bank')['available']);

        // Carte
        $r = $this->actingAs($u, 'sanctum')->postJson('/api/pay/deposit', ['method' => 'card', 'amount' => 5000])->json();
        $this->assertStringStartsWith('https://pay.wace.test/', $r['checkout_url'] ?? data_get($r, 'transaction.checkout_url') ?? json_encode($r));
        $req = DigitwaceRequest::where('operation', 'checkout')->firstOrFail();
        $this->assertSame('pending', $req->status);
        $payload = collect($sent)->first(fn ($s) => str_contains($s[0], 'payin/card'))[1];
        $this->assertSame('CARD', $payload['paymentMethod']);
        $this->assertStringContainsString('/api/card-checkout/', $payload['returnUrl']);

        // Webhook succès -> wallet crédité
        $status = 'SUCCESS';
        $this->postJson('/api/webhooks/digitwace', ['event' => 'transaction.success', 'data' => ['transactionCode' => $req->wace_id]])->assertOk();
        $this->assertSame(5000, (int) $u->wallet->fresh()->balance);
        $tx = $req->transaction->fresh();
        $this->assertSame('successful', $tx->status);
        $this->assertStringContainsString('4242', $tx->source_account);

        // Compte bancaire : échec -> rien crédité, aucun frais
        $status = 'PENDING';
        $r = $this->actingAs($u, 'sanctum')->postJson('/api/pay/deposit', ['method' => 'bank', 'amount' => 3000])->json();
        $bankReq = DigitwaceRequest::where('operation', 'checkout')->latest('id')->first();
        $this->assertSame('bank', $bankReq->transaction->source_rail);
        $this->assertSame('BANK', collect($sent)->first(fn ($s) => str_contains($s[0], 'payin/bank'))[1]['paymentMethod']);
        $status = 'FAILED';
        $page = $this->get('/api/card-checkout/' . $bankReq->transaction->source_external_ref)->assertOk()->getContent();
        $this->assertStringContainsString('Paiement non abouti', $page);
        $this->assertSame('failed', $bankReq->transaction->fresh()->status);
        $this->assertSame(5000, (int) $u->wallet->fresh()->balance);
    }

    public function test_connection_diagnostic_hides_keys(): void
    {
        Http::fake(['*' => Http::response('<html><body>502 Bad Gateway priv</body></html>', 502, ['Server' => 'cloudflare'])]);
        $admin = User::create(['full_name' => 'Super', 'phone' => '242069990001', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $d = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/digitwace/diagnose')->assertOk()->json();
        $this->assertSame(502, $d['http']);
        $this->assertFalse($d['token_ok']);
        $this->assertSame('cloudflare', $d['server']);
        $this->assertStringNotContainsString('priv', $d['body']);
        $this->assertStringContainsString('injoignable', $d['message']);
    }

    public function test_discovery_finds_and_saves_login_endpoint(): void
    {
        config(['flashpay.digitwace.discover_dns_check' => false, 'flashpay.digitwace.private_key' => 'SECRET-XYZ-123']);
        Http::fake(function ($req) {
            $u = $req->url();
            if ($u === 'https://api.wacepay.com/api/v1/auth/login') {
                return Http::response('<html>502 Bad Gateway</html>', 502);
            }
            if ($u === 'https://api.wacepay.com/api/v1/login') {
                return isset($req->data()['publicKey'])
                    ? Http::response(['data' => ['accessToken' => 'tok']], 200)
                    : Http::response(['message' => 'publicKey is required'], 422);
            }
            if (str_contains($u, 'balance')) {
                return Http::response(['data' => [['currency' => 'XAF', 'balance' => 1000]]], 200);
            }
            return Http::response('not found', 404);
        });
        $admin = User::create(['full_name' => 'Super', 'phone' => '242069990001', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $r = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/digitwace/discover')->assertOk()->json();
        $this->assertSame('login', $r['found']['login_path']);
        $this->assertSame('publicKey', $r['found']['login_fields']['public_key']);
        $this->assertStringNotContainsString('SECRET-XYZ', json_encode($r));
        // L'adresse détectée est utilisée par le client
        $this->assertSame('tok', app(\App\Services\Digitwace\DigitwaceClient::class)->token(true));
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/digitwace/discover')->assertOk();
        $this->assertNull(\App\Services\Digitwace\DigitwaceClient::endpointOverride());
    }
}

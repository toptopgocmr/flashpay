<?php

namespace Tests\Feature;

use App\Models\NotchPayRequest;
use App\Services\NotchPay\NotchPayConnector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Notch Pay : collecte (direct charge), versement (transfer), webhook signé revalidé par l'API. */
class NotchPayTest extends TestCase
{
    protected array $sent = [];
    protected string $payStatus = 'processing';
    protected string $trfStatus = 'pending';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.rails.notchpay.enabled' => true, 'flashpay.notchpay.enabled' => true,
            'flashpay.notchpay.public_key' => 'pk_test.x', 'flashpay.notchpay.private_key' => 'sk_test.y', 'flashpay.notchpay.hash_key' => 'hsk_test.z',
            'flashpay.notchpay.base_url' => 'https://api.notch.test',
        ]);
        Http::fake(function ($req) {
            $u = $req->url();
            $this->sent[] = $req;
            return match (true) {
                $req->method() === 'POST' && str_ends_with($u, '/payments') => Http::response(['status' => 'Accepted', 'code' => 201, 'transaction' => ['reference' => 'trx.abc', 'status' => 'pending'], 'authorization_url' => 'https://pay.notch.test/x'], 201),
                $req->method() === 'POST' && str_ends_with($u, '/payments/trx.abc') => Http::response(['status' => 'Accepted', 'code' => 202, 'transaction' => ['reference' => 'trx.abc', 'status' => 'processing']], 202),
                $req->method() === 'GET' && str_ends_with($u, '/payments/trx.abc') => Http::response(['code' => 200, 'transaction' => ['reference' => 'trx.abc', 'status' => $this->payStatus]]),
                $req->method() === 'POST' && str_ends_with($u, '/transfers') => Http::response(['status' => 'Accepted', 'code' => 201, 'transfer' => ['id' => 'trf_1', 'reference' => $req['reference'], 'status' => 'pending']], 201),
                str_ends_with($u, '/transfers/trf_1') => Http::response(['code' => 200, 'transfer' => ['id' => 'trf_1', 'status' => $this->trfStatus]]),
                default => Http::response(['message' => 'not found'], 404),
            };
        });
    }

    public function test_collect_transfer_status_and_signed_webhook(): void
    {
        $c = app(NotchPayConnector::class);

        $r = $c->collect('+237670000000', 5000, 'XAF', 'FP-NP-1');
        $this->assertSame('pending', $r['status']);
        $this->assertSame('trx.abc', $r['external_ref']);
        $init = collect($this->sent)->first(fn ($q) => $q->method() === 'POST' && str_ends_with($q->url(), '/payments'));
        $this->assertSame('pk_test.x', $init->header('Authorization')[0]);
        $this->assertTrue(app(\App\Services\NotchPay\NotchPayClient::class)->sandbox());
        $this->assertSame('cm.mtn', $init['locked_channel']);
        $proc = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/payments/trx.abc'));
        $this->assertSame('cm.mtn', $proc['channel']);
        $this->assertSame('+237670000000', $proc['data']['phone']);

        $this->assertSame('pending', $c->checkStatus('trx.abc')['status']);
        $this->payStatus = 'complete';
        $this->assertSame('successful', $c->checkStatus('trx.abc')['status']);

        $t = $c->disburse('+237690000000', 3000, 'XAF', 'FP-NP-2');
        $this->assertSame('pending', $t['status']);
        $trf = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/transfers'));
        $this->assertSame('sk_test.y', $trf->header('X-Grant')[0]);
        $this->assertSame('cm.orange', $trf['channel']);
        $this->assertSame('FP-NP-2', $trf['reference']);

        // Webhook : signature invalide refusée ; valide → statut relu chez Notch Pay
        $body = json_encode(['type' => 'transfer.complete', 'data' => ['id' => 'trf_1', 'reference' => 'FP-NP-2']]);
        $this->call('POST', '/api/webhooks/notchpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NOTCH_SIGNATURE' => 'bad'], $body)->assertStatus(403);
        $this->trfStatus = 'complete';
        $this->call('POST', '/api/webhooks/notchpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_NOTCH_SIGNATURE' => hash_hmac('sha256', $body, 'hsk_test.z')], $body)->assertOk();
        $this->assertSame('successful', NotchPayRequest::where('reference', 'FP-NP-2')->first()->status);
        $this->assertTrue(collect($this->sent)->contains(fn ($q) => str_ends_with($q->url(), '/transfers/trf_1')));
    }
}

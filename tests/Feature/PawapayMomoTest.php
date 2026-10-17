<?php

namespace Tests\Feature;

use App\Models\RemitRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** pawaPay passerelle nationale : collecte (deposits) et versements (payouts) MTN / Airtel Congo. */
class PawapayMomoTest extends TestCase
{
    protected array $sent = [];
    protected string $depositStatus = 'PROCESSING';
    protected string $payoutStatus = 'PROCESSING';
    protected float $balance = 1000000;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.pawapay.enabled' => true, 'flashpay.pawapay.token' => 'tok-test',
            'flashpay.pawapay.base_url' => 'https://api.sandbox.pawapay.io',
            'flashpay.rails.pawapay_momo.enabled' => true, 'flashpay.rails.pawapay.enabled' => true,
        ]);
        Http::fake(function ($req) {
            $u = $req->url();
            $this->sent[] = $req;
            $path = parse_url($u, PHP_URL_PATH);
            return match (true) {
                $path === '/v2/predict-provider' => Http::response(['country' => 'COG', 'phoneNumber' => preg_replace('/\D/', '', $req['phoneNumber']),
                    'provider' => str_starts_with(substr(preg_replace('/\D/', '', $req['phoneNumber']), 3), '06') ? 'MTN_MOMO_COG' : 'AIRTEL_COG']),
                $req->method() === 'POST' && $path === '/v2/deposits' => Http::response(['depositId' => $req['depositId'], 'status' => 'ACCEPTED', 'created' => now()->toIso8601String()]),
                $req->method() === 'POST' && $path === '/v2/payouts' => Http::response(['payoutId' => $req['payoutId'], 'status' => 'ACCEPTED']),
                str_starts_with($path, '/v2/deposits/') => Http::response(['status' => 'FOUND', 'data' => ['depositId' => basename($path), 'status' => $this->depositStatus]]),
                str_starts_with($path, '/v2/payouts/') => Http::response(['status' => 'FOUND', 'data' => ['payoutId' => basename($path), 'status' => $this->payoutStatus,
                    'failureReason' => $this->payoutStatus === 'FAILED' ? ['failureCode' => 'RECIPIENT_NOT_FOUND', 'failureMessage' => 'Recipient not found'] : null]]),
                $path === '/v2/active-conf' => Http::response(['countries' => [
                    ['country' => 'COG', 'providers' => [
                        ['provider' => 'MTN_MOMO_COG', 'displayName' => 'MTN', 'currencies' => [['currency' => 'XAF', 'operationTypes' => [
                            'DEPOSIT' => ['status' => 'OPERATIONAL'], 'PAYOUT' => ['status' => 'OPERATIONAL']]]]],
                        // format « tableau » aussi présent dans la doc
                        ['provider' => 'AIRTEL_COG', 'displayName' => 'Airtel', 'currencies' => [['currency' => 'XAF', 'operationTypes' => [
                            ['operationType' => 'DEPOSIT', 'status' => 'OPERATIONAL'], ['operationType' => 'PAYOUT', 'status' => 'DELAYED']]]]],
                    ]],
                    // Cameroun : versement seulement
                    ['country' => 'CMR', 'providers' => [['provider' => 'MTN_MOMO_CMR', 'displayName' => 'MTN', 'currencies' => [['currency' => 'XAF', 'operationTypes' => [
                        'DEPOSIT' => ['status' => 'CLOSED'], 'PAYOUT' => ['status' => 'OPERATIONAL']]]]]]],
                ]]),
                $path === '/v2/wallet-balances' => Http::response(['balances' => [['country' => 'COG', 'currency' => 'XAF', 'balance' => (string) $this->balance]]]),
                default => Http::response(['message' => 'not found'], 404),
            };
        });
    }

    protected function client(string $phone, int $balance = 0): User
    {
        $u = User::create(['full_name' => 'CLIENT TEST', 'phone' => $phone, 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        return $u->fresh('wallet');
    }

    protected function admin(): User
    {
        $a = User::create(['full_name' => 'ADMIN', 'phone' => '242060000099', 'password' => bcrypt('x'), 'status' => 'active']);
        $a->assignRole('super_admin');
        return $a;
    }

    public function test_console_assigns_pawapay_gateway_per_country(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/GW', ['partner' => 'pawapay'])->assertStatus(422);
        // Cameroun : collecte fermée sur le compte pawaPay, versement ouvert
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CM', ['collect_partner' => 'pawapay'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CM', ['payout_partner' => 'pawapay'])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CG', ['partner' => 'pawapay'])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'PawaPay'));

        $d = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/corridors')->assertOk()->json();
        $card = collect($d['partners'])->firstWhere('key', 'pawapay');
        $this->assertTrue($card['ready']);
        $this->assertSame(['collect', 'payout'], $card['flows']);
        $this->assertSame(1, $card['collect_countries']);
        $cg = collect($d['corridors'])->firstWhere('country', 'CG');
        $this->assertSame('pawapay', $cg['collect_partner']);
        $this->assertTrue($cg['pawapay']['collect'] && $cg['pawapay']['payout']);
        $this->assertSame(['MTN', 'Airtel'], $cg['pawapay']['providers']);
        $this->assertNull(collect($d['corridors'])->firstWhere('country', 'GA')['pawapay']);
        $this->assertTrue($card['coverage_live']);
        $this->assertSame(2, $card['coverage_countries']);
        $this->assertSame(1, $card['coverage_payin']);

        $flows = app(\App\Services\Peex\PeexFlowService::class);
        $route = app(\App\Services\Peex\PeexCorridors::class)->resolve('+242061112233');
        $this->assertSame('pawapay_momo', $flows->collectRailFor($route));
        $this->assertSame('pawapay_momo', $flows->payoutRailFor($route));

        // Non configuré : refus explicite, jamais de routage silencieux vers PEEX
        config(['flashpay.pawapay.token' => '']);
        $this->expectExceptionMessage('PawaPay');
        $flows->payoutRailFor($route);
    }

    public function test_deposit_confirmed_only_after_api_check_then_payout_failure_refunds(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/corridors/CG', ['partner' => 'pawapay'])->assertOk();
        $u = $this->client('242061112233', 0);
        $flows = app(\App\Services\Peex\PeexFlowService::class);

        // 1. Recharge du wallet depuis MTN MoMo Congo → POST /v2/deposits
        $tx = $flows->deposit($u, '+242061112233', 5000);
        $this->assertSame('pawapay_momo', $tx->source_rail);
        $this->assertSame('processing', $tx->status);
        $this->assertSame('awaiting_source', $tx->stage);
        $dep = collect($this->sent)->first(fn ($q) => $q->method() === 'POST' && str_ends_with($q->url(), '/v2/deposits'));
        $this->assertSame('Bearer tok-test', $dep->header('Authorization')[0]);
        $this->assertSame('MTN_MOMO_COG', $dep['payer']['accountDetails']['provider']);
        $this->assertSame('242061112233', $dep['payer']['accountDetails']['phoneNumber']);
        $this->assertSame('XAF', $dep['currency']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 ]{4,22}$/', $dep['customerMessage']);
        $req = RemitRequest::where('partner', 'pawapay_momo')->where('method', 'deposit')->firstOrFail();
        $this->assertSame($dep['depositId'], $req->partner_ref);

        // Callback « COMPLETED » alors que l'API dit encore PROCESSING : non cru
        $this->postJson('/api/webhooks/pawapay', ['depositId' => $req->partner_ref, 'status' => 'COMPLETED'])->assertOk();
        $this->assertSame('processing', $tx->fresh()->status);
        $this->assertSame(0, (int) $u->wallet->fresh()->balance);

        // Statut COMPLETED confirmé par l'API → wallet crédité (une seule fois malgré un 2e callback)
        $this->depositStatus = 'COMPLETED';
        Cache::flush();
        $this->postJson('/api/webhooks/pawapay', ['depositId' => $req->partner_ref, 'status' => 'COMPLETED'])->assertOk();
        Cache::flush();
        $this->postJson('/api/webhooks/pawapay', ['depositId' => $req->partner_ref, 'status' => 'COMPLETED'])->assertOk();
        $this->assertSame('successful', $tx->fresh()->status);
        $credited = (int) $u->wallet->fresh()->balance;
        $this->assertGreaterThan(0, $credited);

        // 2. Retrait vers Airtel Money Congo → POST /v2/payouts, échec → remboursement intégral
        $before = $credited;
        $u = $u->fresh('wallet');
        $out = $flows->payout($u, '+242051112233', 2000);
        $this->assertSame('pawapay_momo', $out->destination_rail);
        $pay = collect($this->sent)->first(fn ($q) => $q->method() === 'POST' && str_ends_with($q->url(), '/v2/payouts'));
        $this->assertSame('AIRTEL_COG', $pay['recipient']['accountDetails']['provider']);
        $this->assertSame('2000', $pay['amount']);
        $this->assertSame($out->reference, $pay['clientReferenceId']);
        $this->assertLessThan($before, (int) $u->wallet->fresh()->balance);

        $this->payoutStatus = 'FAILED';
        Artisan::call('remit:sync');
        $this->assertSame('reversed', $out->fresh()->status);
        $this->assertSame($before, (int) $u->wallet->fresh()->balance);
        $this->assertStringContainsString('PawaPay', (string) $out->fresh()->failure_reason);
    }

    public function test_quote_blocks_payout_when_pawapay_balance_is_too_low(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/corridors/CG', ['payout_partner' => 'pawapay'])->assertOk();
        $u = $this->client('242069990002', 50000);
        $this->balance = 1000;
        $q = app(\App\Services\Peex\PeexFlowService::class)->quoteWithdraw($u, '+242061112233', 20000);
        $this->assertFalse($q['available']);
        $this->assertContains('payout_unavailable', $q['problem_codes'] ?? []);
        $this->assertFalse(collect($this->sent)->contains(fn ($q) => str_ends_with($q->url(), '/v2/payouts')));
    }
}

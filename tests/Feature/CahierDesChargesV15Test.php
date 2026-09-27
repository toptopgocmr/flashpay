<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookDelivery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Couverture des exigences ajoutées par le cahier des charges v1.5
 * (sécurité, plafonds, agents, marchands, e-commerce, social, litiges, back-office).
 */
class CahierDesChargesV15Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    protected function mk(string $role, string $phone, int $balance = 0, array $extra = []): User
    {
        $u = User::create(['full_name' => "Test {$role} {$phone}", 'phone' => $phone, 'password' => 'secret123'] + $extra);
        $u->assignRole($role);
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        if ($role === 'merchant') {
            Merchant::create(['user_id' => $u->id, 'business_name' => 'Boutique ' . $phone, 'country' => 'CG', 'qr_code_token' => 'FPM-' . $phone, 'validation_status' => 'approved']);
        }
        if ($role === 'agent') {
            Agent::create(['user_id' => $u->id, 'validation_status' => 'approved', 'validated_at' => now(), 'country' => 'CG']);
        }
        return $u->fresh();
    }

    protected function admin(): User
    {
        return User::where('phone', '242060000000')->first();
    }

    // ================================================================ Sécurité

    public function test_register_requires_otp_then_pin_protects_transfers(): void
    {
        config(['security.require_otp_on_register' => true, 'security.pin_mandatory' => true]);

        $this->postJson('/api/auth/register', ['full_name' => 'Awa', 'phone' => '242061000001', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'profile' => 'client'])
            ->assertStatus(422);

        $code = $this->postJson('/api/auth/otp', ['phone' => '242061000001', 'purpose' => 'register'])->assertOk()->json('debug_code');
        $this->postJson('/api/auth/register', ['full_name' => 'Awa', 'phone' => '242061000001', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'profile' => 'client', 'otp' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'otp_invalid');
        $r = $this->postJson('/api/auth/register', ['full_name' => 'Awa', 'phone' => '242061000001', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'profile' => 'client', 'otp' => $code, 'device_id' => 'dev-1'])
            ->assertCreated()->assertJsonPath('user.kyc_tier', 0)->assertJsonPath('user.has_pin', false);

        $awa = User::where('phone', '242061000001')->first();
        $awa->wallet->update(['balance' => 50000]);
        $this->mk('client', '242062000002');
        $this->actingAs($awa, 'sanctum');

        // PIN non défini : 428
        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242062000002', 'amount' => 1000])
            ->assertStatus(428)->assertJsonPath('code', 'pin_not_set');
        $this->postJson('/api/me/pin', ['pin' => '1111'])->assertStatus(422)->assertJsonPath('code', 'pin_weak');
        $this->postJson('/api/me/pin', ['pin' => '4827'])->assertOk();

        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242062000002', 'amount' => 1000], ['X-FlashPay-Pin' => '0000'])
            ->assertStatus(422)->assertJsonPath('code', 'pin_invalid');
        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242062000002', 'amount' => 1000], ['X-FlashPay-Pin' => '4827'])
            ->assertStatus(201);

        // Verrouillage après 5 erreurs
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/me/pin/verify', ['pin' => '9999'])->assertStatus(422);
        }
        $this->postJson('/api/me/pin/verify', ['pin' => '9999'])->assertStatus(423);
        $this->postJson('/api/me/pin/verify', ['pin' => '4827'])->assertStatus(423);
        $this->assertTrue(AppNotification::where('user_id', $awa->id)->where('type', 'account_blocked')->exists());
    }

    public function test_new_device_login_requires_otp_and_notifies(): void
    {
        $u = $this->mk('client', '242061000011');
        $this->postJson('/api/auth/login', ['phone' => '242061000011', 'password' => 'secret123', 'device_id' => 'phone-A'])->assertOk();
        $r = $this->postJson('/api/auth/login', ['phone' => '242061000011', 'password' => 'secret123', 'device_id' => 'phone-B'])->assertStatus(202)->assertJsonPath('otp_required', true);
        $this->postJson('/api/auth/login', ['phone' => '242061000011', 'password' => 'secret123', 'device_id' => 'phone-B', 'otp' => $r->json('debug_code')])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $u->id)->where('type', 'new_device')->exists());
        $this->assertSame(2, $u->devices()->count());
    }

    public function test_pin_reset_and_lost_phone_blocking(): void
    {
        $u = $this->mk('client', '242061000012', 10000);
        $u->forceFill(['id_number' => 'CG123'])->save();
        $otp = $this->postJson('/api/auth/otp', ['phone' => '242061000012', 'purpose' => 'pin_reset'])->json('debug_code');
        $this->postJson('/api/auth/pin/reset', ['phone' => '242061000012', 'otp' => $otp, 'password' => 'secret123', 'id_number' => 'XX', 'new_pin' => '5823'])->assertStatus(422);
        $otp = null;
        $this->travel(31)->seconds();
        $otp = $this->postJson('/api/auth/otp', ['phone' => '242061000012', 'purpose' => 'pin_reset'])->json('debug_code');
        $this->postJson('/api/auth/pin/reset', ['phone' => '242061000012', 'otp' => $otp, 'password' => 'secret123', 'id_number' => 'cg123', 'new_pin' => '5823'])->assertOk();
        $this->assertTrue($u->fresh()->hasPin());

        $this->postJson('/api/auth/report-lost', ['phone' => '242061000012', 'password' => 'secret123'])->assertOk();
        $this->assertTrue($u->fresh()->blocked_until->isFuture());
        $this->actingAs($u->fresh(), 'sanctum');
        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242061000099', 'amount' => 100], ['X-FlashPay-Pin' => '5823'])->assertStatus(423);
    }

    // ================================================================ Plafonds & KYC (§12)

    public function test_kyc_tier_limits_are_blocking_and_raised_after_review(): void
    {
        Storage::fake('local');
        $c = $this->mk('client', '242061000021', 400000);
        $this->mk('client', '242061000022');
        $this->actingAs($c, 'sanctum');

        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242061000022', 'amount' => 150000])
            ->assertStatus(422)->assertJsonPath('code', 'limit_exceeded')->assertJsonPath('limit', 'per_operation');
        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '221771234567', 'amount' => 5000])
            ->assertStatus(422)->assertJsonPath('code', 'kyc_required');

        // Envoi des pièces
        $this->post('/api/kyc/documents', ['type' => 'id_card', 'file' => UploadedFile::fake()->image('cni.jpg'), 'id_number' => 'CG999'], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/kyc/documents', ['type' => 'selfie', 'file' => UploadedFile::fake()->image('selfie.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('submitted', $c->fresh()->kyc_status);
        $this->assertTrue(AppNotification::where('audience', 'admin')->where('type', 'kyc_pending')->exists());

        $this->actingAs($this->admin(), 'sanctum');
        $docs = $this->getJson('/api/support/desk/kyc')->assertOk()->json('data');
        $this->assertCount(2, $docs);
        $this->postJson("/api/admin/kyc/documents/{$docs[1]['id']}/review", ['decision' => 'approved'])->assertOk()->assertJsonPath('user.kyc_tier', 1);
        $this->postJson("/api/admin/kyc/documents/{$docs[0]['id']}/review", ['decision' => 'approved'])->assertOk()->assertJsonPath('user.kyc_tier', 2)->assertJsonPath('user.kyc_status', 'verified');
        $this->assertTrue(AppNotification::where('user_id', $c->id)->where('type', 'limit_raised')->exists());

        $this->actingAs($c->fresh(), 'sanctum');
        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242061000022', 'amount' => 150000])->assertStatus(201);
        $this->getJson('/api/me')->assertJsonPath('limits.tier', 2)->assertJsonPath('limits.usage.daily', 151500);
    }

    public function test_idempotency_key_prevents_double_debit(): void
    {
        $c = $this->mk('client', '242061000031', 20000);
        $this->mk('client', '242061000032');
        $this->actingAs($c, 'sanctum');
        $body = ['source' => 'wallet', 'destination_phone' => '242061000032', 'amount' => 1000];
        $first = $this->postJson('/api/pay/transfer', $body, ['Idempotency-Key' => 'retry-abc-123'])->assertStatus(201);
        $this->postJson('/api/pay/transfer', $body, ['Idempotency-Key' => 'retry-abc-123'])->assertStatus(201)
            ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('reference', $first->json('reference'));
        $this->postJson('/api/pay/transfer', $body + ['note' => 'x'], ['Idempotency-Key' => 'retry-abc-123'])->assertStatus(422);
        $this->assertSame(1, Transaction::where('type', 'p2p')->count());
        $this->assertSame(20000 - 1010, $c->wallet->fresh()->balance);
    }

    public function test_fraud_velocity_blocks_account_and_alerts_admin(): void
    {
        config(['limits.fraud.velocity_max_operations' => 3]);
        $c = $this->mk('client', '242061000041', 20000);
        $this->mk('client', '242061000042');
        $this->actingAs($c, 'sanctum');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242061000042', 'amount' => 100])->assertStatus(201);
        }
        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242061000042', 'amount' => 100])
            ->assertStatus(423)->assertJsonPath('code', 'account_temporarily_blocked');
        $this->actingAs($this->admin(), 'sanctum');
        $alert = $this->getJson('/api/admin/fraud-alerts')->assertOk()->json('data.0');
        $this->assertSame('velocity', $alert['rule']);
        $this->postJson("/api/admin/fraud-alerts/{$alert['id']}", ['decision' => 'cleared', 'unblock' => true])->assertOk();
        $this->assertNull($c->fresh()->blocked_until);
    }

    // ================================================================ Notifications (§11)

    public function test_p2p_notifies_sender_and_receiver(): void
    {
        $a = $this->mk('client', '242061000051', 20000);
        $b = $this->mk('client', '242061000052');
        $this->actingAs($a, 'sanctum');
        $this->postJson('/api/pay/transfer', ['source' => 'wallet', 'destination_phone' => '242061000052', 'amount' => 2000])->assertStatus(201);
        $this->actingAs($b, 'sanctum');
        $r = $this->getJson('/api/notifications')->assertOk();
        $this->assertSame('money_received', $r->json('data.0.type'));
        $this->assertSame(1, $r->json('unread'));
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->getJson('/api/notifications')->json('unread'));
        $this->assertTrue(AppNotification::where('user_id', $a->id)->where('type', 'money_sent')->exists());
    }

    // ================================================================ Agents (§3.1)

    public function test_float_request_admin_and_super_agent_flows(): void
    {
        $agent = $this->mk('agent', '242061000061');
        $this->actingAs($agent, 'sanctum');
        $req = $this->postJson('/api/agent/float-requests', ['amount' => 100000, 'method' => 'cash_deposit', 'proof_reference' => 'BORD-12'])->assertCreated()->json();
        $this->assertSame('pending', $req['status']);

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson('/api/admin/float-requests')->assertOk()->assertJsonPath('data.0.id', $req['id']);
        $this->postJson("/api/admin/float-requests/{$req['id']}/review", ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame(100000, $agent->wallet->fresh()->balance);
        $this->assertTrue(AuditLog::where('action', 'float_request.approve')->exists());

        // Super-agent
        $super = $this->mk('agent', '242061000062', 500000);
        $super->agent->update(['is_super_agent' => true]);
        $sub = $this->mk('agent', '242061000063');
        $sub->agent->update(['parent_agent_id' => $super->agent->id]);
        $this->actingAs($sub->fresh(), 'sanctum');
        $r2 = $this->postJson('/api/agent/float-requests', ['amount' => 50000, 'method' => 'super_agent'])->assertCreated()->json();
        $this->actingAs($super->fresh(), 'sanctum');
        $this->assertCount(1, $this->getJson('/api/agent/float-requests')->json('to_review'));
        $this->postJson("/api/agent/float-requests/{$r2['id']}/review", ['decision' => 'approve'])->assertOk();
        $this->assertSame(50000, $sub->wallet->fresh()->balance);
        $this->assertSame(500000 - 50000 + 50, $super->wallet->fresh()->balance); // commission 0,1 %
    }

    public function test_agent_cash_in_by_phone_requires_client_otp_and_journal(): void
    {
        config(['security.cash_in_client_confirmation' => true]);
        $client = $this->mk('client', '242061000071');
        $agent = $this->mk('agent', '242061000072', 100000);
        $this->actingAs($agent, 'sanctum');
        $r = $this->postJson('/api/agent/cash-in', ['client_phone' => '242061000071', 'amount' => 10000])->assertStatus(202)->assertJsonPath('confirmation_required', true);
        $this->postJson('/api/agent/cash-in', ['client_phone' => '242061000071', 'amount' => 12000, 'otp' => $r->json('debug_code')])->assertStatus(422);
        $this->travel(31)->seconds();
        $r = $this->postJson('/api/agent/cash-in', ['client_phone' => '242061000071', 'amount' => 10000])->assertStatus(202);
        $this->postJson('/api/agent/cash-in', ['client_phone' => '242061000071', 'amount' => 10000, 'otp' => $r->json('debug_code')])->assertStatus(201);
        $this->assertSame(10000, $client->wallet->fresh()->balance);

        $j = $this->getJson('/api/agent/journal')->assertOk()->json('data');
        $this->assertSame('debit', $j[0]['direction']);   // dépôt 10 000 − commission 100 (même opération)
        $this->assertSame(9900, $j[0]['amount']);
        $this->assertSame(100000, $j[0]['balance_before']);
        $this->assertSame(90100, $j[0]['balance_after']);
        $this->assertSame(90100, $agent->wallet->fresh()->balance);
        $rec = $this->getJson('/api/agent/reconciliation')->assertOk();
        $this->assertTrue($rec->json('balanced'));
        $this->assertSame(10000, $rec->json('cash_received'));
        $this->getJson('/api/agent/float')->assertOk()->assertJsonPath('origins.commissions', 100);
    }

    // ================================================================ Marchands (§3.2)

    public function test_dynamic_qr_signed_single_use_and_expiry(): void
    {
        $m = $this->mk('merchant', '242061000081');
        $c = $this->mk('client', '242061000082', 50000);
        $this->actingAs($m, 'sanctum');
        $qr = $this->postJson('/api/merchant/payment-requests', ['amount' => 7500, 'description' => 'Table 4'])->assertCreated()->json();
        parse_str(parse_url($qr['qr_payload'], PHP_URL_QUERY), $q);

        $this->actingAs($c, 'sanctum');
        $this->getJson("/api/pay/requests/{$q['r']}?s=bad")->assertStatus(404);
        $this->getJson("/api/pay/requests/{$q['r']}?s={$q['s']}")->assertOk()->assertJsonPath('amount', 7500);
        $this->postJson("/api/pay/requests/{$q['r']}", ['s' => $q['s']])->assertStatus(201);
        $this->postJson("/api/pay/requests/{$q['r']}", ['s' => $q['s']])->assertStatus(422)->assertJsonPath('code', 'request_not_payable');
        $this->assertSame(42500, $c->wallet->fresh()->balance);

        $this->actingAs($m, 'sanctum');
        $this->getJson("/api/merchant/payment-requests/{$q['r']}")->assertJsonPath('status', 'paid');
        $this->assertTrue(AppNotification::where('user_id', $m->id)->where('type', 'payment_received')->where('sound', true)->exists());

        // Expiration
        $qr2 = $this->postJson('/api/merchant/payment-requests', ['amount' => 1000])->json();
        $this->travel(4)->minutes();
        Artisan::call('flashpay:maintenance');
        $this->assertSame('expired', \App\Models\PaymentRequest::where('token', $qr2['token'])->value('status'));
        $this->assertTrue(AppNotification::where('user_id', $m->id)->where('type', 'dynamic_qr_expired')->exists());
    }

    public function test_cashier_subaccount_collects_and_can_be_revoked(): void
    {
        $m = $this->mk('merchant', '242061000091');
        $outlet = \App\Models\MerchantOutlet::create(['merchant_id' => $m->merchant->id, 'name' => 'Caisse Poto-Poto', 'qr_code_token' => 'FPO-X1']);
        $c = $this->mk('client', '242061000092', 50000);
        $this->actingAs($m, 'sanctum');
        $cashier = $this->postJson('/api/merchant/cashiers', ['full_name' => 'Caissier Jean', 'phone' => '242061000093', 'password' => 'caisse123', 'outlet_id' => $outlet->id])->assertCreated()->json();

        $login = $this->postJson('/api/auth/login', ['phone' => '242061000093', 'password' => 'caisse123', 'profile' => 'cashier'])->assertOk();
        $cashierUser = User::where('phone', '242061000093')->first();
        $this->actingAs($cashierUser, 'sanctum');
        $qr = $this->postJson('/api/merchant/payment-requests', ['amount' => 5000])->assertCreated()->json();
        $this->postJson('/api/merchant/withdraw', ['amount' => 100])->assertStatus(403); // pas de retrait pour un caissier

        $this->actingAs($c, 'sanctum');
        $this->postJson("/api/pay/requests/{$qr['token']}")->assertStatus(201);
        $this->assertSame(4950, $m->wallet->fresh()->balance);

        $this->actingAs($m, 'sanctum');
        $rep = $this->getJson('/api/merchant/reports')->assertOk();
        $this->assertSame('Caissier Jean', $rep->json('by_cashier.0.name'));
        $this->assertSame('Caisse Poto-Poto', $rep->json('by_outlet.0.name'));
        $csv = $this->get('/api/merchant/statement')->assertOk()->streamedContent();
        $this->assertStringContainsString('QR dynamique', $csv);

        $this->postJson("/api/merchant/cashiers/{$cashier['id']}", ['action' => 'revoke', 'reason' => 'Départ'])->assertOk()->assertJsonPath('status', 'revoked');
        $this->assertSame(0, $cashierUser->tokens()->count());
        $this->postJson('/api/auth/login', ['phone' => '242061000093', 'password' => 'caisse123', 'profile' => 'cashier'])->assertStatus(403);
    }

    public function test_merchant_partial_refund_in_store(): void
    {
        $m = $this->mk('merchant', '242061000101');
        $c = $this->mk('client', '242061000102', 50000);
        $this->actingAs($c, 'sanctum');
        $tx = $this->postJson('/api/pay/merchant', ['merchant_code' => 'FPM-242061000101', 'source' => 'wallet', 'amount' => 10000])->assertStatus(201)->json();
        $this->assertSame(9900, $m->wallet->fresh()->balance);

        $this->actingAs($m, 'sanctum');
        $this->postJson('/api/merchant/refunds', ['transaction_id' => $tx['id'], 'amount' => 4000, 'reason' => 'Article retourné'])->assertCreated();
        $this->assertSame(44000, $c->wallet->fresh()->balance);
        $this->assertSame(9900 - 3960, $m->wallet->fresh()->balance);
        $this->postJson('/api/merchant/refunds', ['transaction_id' => $tx['id'], 'amount' => 7000])->assertStatus(422);
        $this->assertTrue(AppNotification::where('user_id', $c->id)->where('type', 'refund_received')->exists());
    }

    // ================================================================ E-commerce (§4.7)

    public function test_ecommerce_sandbox_and_live_flows_with_signed_webhooks(): void
    {
        $m = $this->mk('merchant', '242061000111');
        $c = $this->mk('client', '242061000112', 80000);
        $this->actingAs($m, 'sanctum');
        $sandbox = $this->postJson('/api/merchant/api-keys', ['environment' => 'sandbox', 'webhook_url' => 'https://boutique.test/webhook'])->assertCreated()->json();
        // Production refusée tant que la recette sandbox n'est pas faite
        $this->postJson('/api/merchant/api-keys', ['environment' => 'live'])->assertStatus(422)->assertJsonPath('code', 'integration_not_validated');
        $this->getJson('/api/merchant/api-keys')->assertJsonPath('integration.status', 'in_progress');
        $this->app['auth']->forgetGuards();

        // Sans clé / sans Idempotency-Key
        $this->postJson('/api/v1/payment-intents', ['amount' => 1000])->assertStatus(401);
        $h = ['Authorization' => 'Bearer ' . $sandbox['secret_key']];
        $this->postJson('/api/v1/payment-intents', ['amount' => 1000], $h)->assertStatus(400);

        // Sandbox : simulation
        $pi = $this->postJson('/api/v1/payment-intents', ['amount' => 12000, 'order_reference' => 'CMD-1'], $h + ['Idempotency-Key' => 'order-cmd-1'])->assertCreated()->json();
        $this->assertSame('pending', $pi['status']);
        $this->postJson("/api/v1/payment-intents/{$pi['id']}/confirm", ['test_outcome' => 'succeeded'], $h)->assertOk()->assertJsonPath('payment_intent.status', 'confirmed');
        $this->assertSame(0, $m->wallet->fresh()->balance);
        $d = WebhookDelivery::where('event', 'payment.succeeded')->first();
        $this->assertSame('delivered', $d->status);
        Http::assertSent(function ($req) use ($sandbox) {
            if (! $req->hasHeader('FlashPay-Signature')) {
                return false;
            }
            sscanf($req->header('FlashPay-Signature')[0], 't=%d,v1=%s', $t, $v1);
            return hash_equals(hash_hmac('sha256', $t . '.' . $req->body(), $sandbox['webhook_secret']), $v1);
        });

        // Recette sandbox réussie → intégration validée automatiquement, clés de production autorisées
        $this->actingAs($m, 'sanctum');
        $this->getJson('/api/merchant/api-keys')->assertJsonPath('integration.status', 'sandbox_validated');
        $this->assertTrue(AppNotification::where('user_id', $m->id)->where('type', 'integration_validated')->exists());
        $live = $this->postJson('/api/merchant/api-keys', ['environment' => 'live', 'webhook_url' => 'https://boutique.test/webhook'])->assertCreated()->json();
        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson('/api/admin/ecommerce/integrations')->assertOk()->assertJsonPath('data.0.status', 'live_pending');
        $this->app['auth']->forgetGuards();

        // Live : le client paie dans l'app (PIN)
        $hl = ['Authorization' => 'Bearer ' . $live['secret_key']];
        $pi2 = $this->postJson('/api/v1/payment-intents', ['amount' => 20000, 'customer_phone' => '242061000112', 'return_url' => 'https://boutique.test/merci'], $hl + ['Idempotency-Key' => 'order-cmd-2'])->assertCreated()->json();
        $this->getJson("/api/v1/payment-intents/{$pi['id']}", $hl)->assertStatus(404); // autre environnement
        $this->postJson("/api/v1/payment-intents/{$pi2['id']}/confirm", [], $hl)->assertOk()->assertJsonPath('next_action.app_notified', true);
        $this->app['auth']->forgetGuards();

        $this->actingAs($c, 'sanctum');
        $this->assertCount(1, $this->getJson('/api/pay/intents')->json());
        $this->postJson("/api/pay/intents/{$pi2['id']}")->assertStatus(201)->assertJsonPath('status', 'confirmed');
        $this->assertSame(60000, $c->wallet->fresh()->balance);
        $this->assertSame(19800, $m->wallet->fresh()->balance);
        $this->assertSame('ecommerce_payment', Transaction::latest('id')->first()->type);

        // Remboursement partiel par API
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/refunds', ['payment_intent' => $pi2['id'], 'amount' => 5000], $hl + ['Idempotency-Key' => 'refund-cmd-2'])->assertCreated()->assertJsonPath('status', 'completed');
        $this->getJson("/api/v1/payment-intents/{$pi2['id']}", $hl)->assertJsonPath('status', 'partially_refunded')->assertJsonPath('amount_refunded', 5000);
        $this->assertSame(65000, $c->wallet->fresh()->balance);
        $this->assertTrue(WebhookDelivery::where('event', 'refund.completed')->exists());

        $this->actingAs($this->admin(), 'sanctum');
        $detail = $this->getJson("/api/admin/ecommerce/integrations/{$m->merchant->id}")->assertOk();
        $this->assertSame('live', $detail->json('status'));
        $this->assertNotEmpty($detail->json('recent_webhooks'));
        $this->assertNotNull($m->merchant->fresh()->integration_live_at);

        // Page de checkout web
        $this->get("/checkout/{$pi2['id']}")->assertOk()->assertSee('confirmé', false);
    }

    public function test_web_checkout_with_otp_and_pin(): void
    {
        $m = $this->mk('merchant', '242061000121');
        $c = $this->mk('client', '242061000122', 30000);
        $c->forceFill(['pin_hash' => bcrypt('7391')])->save();
        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson("/api/admin/ecommerce/integrations/{$m->merchant->id}", ['action' => 'validate', 'note' => 'Intégration testée avec le marchand'])->assertOk()->assertJsonPath('status', 'sandbox_validated');
        $this->actingAs($m, 'sanctum');
        $live = $this->postJson('/api/merchant/api-keys', ['environment' => 'live'])->json();
        $this->app['auth']->forgetGuards();
        $pi = $this->postJson('/api/v1/payment-intents', ['amount' => 3000, 'return_url' => 'https://shop.test/ok'], ['Authorization' => 'Bearer ' . $live['secret_key'], 'Idempotency-Key' => 'web-checkout-1'])->json();

        $this->get("/checkout/{$pi['id']}")->assertOk()->assertSee('Recevoir un code SMS', false);
        $page = $this->post("/checkout/{$pi['id']}", ['step' => 'phone', 'phone' => '242061000122'])->assertOk()->getContent();
        preg_match('/Sandbox : code (\d{6})/', $page, $mm);
        $this->post("/checkout/{$pi['id']}", ['step' => 'otp', 'phone' => '242061000122', 'otp' => $mm[1], 'pin' => '7391'])
            ->assertRedirect("https://shop.test/ok?payment_intent={$pi['id']}&status=confirmed");
        $this->assertSame(27000, $c->wallet->fresh()->balance);
    }

    // ================================================================ Social (§3.5)

    public function test_red_envelopes_fixed_random_and_expiry(): void
    {
        $s = $this->mk('client', '242061000131', 100000);
        $a = $this->mk('client', '242061000132');
        $b = $this->mk('client', '242061000133');
        $this->actingAs($s, 'sanctum');
        $fixed = $this->postJson('/api/gifts', ['mode' => 'fixed', 'amount' => 5000, 'recipients' => ['242061000132', '242061000133'], 'message' => 'Joyeux anniversaire', 'occasion' => 'anniversaire'])->assertCreated()->json();
        $this->assertSame(90000, $s->wallet->fresh()->balance);

        $this->actingAs($a, 'sanctum');
        $this->postJson("/api/gifts/{$fixed['code']}/claim")->assertOk()->assertJsonPath('amount', 5000);
        $this->postJson("/api/gifts/{$fixed['code']}/claim")->assertStatus(422);
        $this->assertSame(5000, $a->wallet->fresh()->balance);

        $this->actingAs($s, 'sanctum');
        $random = $this->postJson('/api/gifts', ['mode' => 'random', 'amount' => 10000, 'shares' => 3])->assertCreated()->json();
        $got = 0;
        foreach ([$a, $b] as $u) {
            $this->actingAs($u, 'sanctum');
            $got += $this->postJson("/api/gifts/{$random['code']}/claim")->assertOk()->json('amount');
        }
        $this->assertLessThan(10000, $got);

        // Expiration : le reste (fixe : 5000 de B non réclamé ; aléatoire : 10000 − $got) revient à l'expéditeur
        $this->travel(25)->hours();
        Artisan::call('flashpay:maintenance');
        $this->assertSame(80000 + 5000 + (10000 - $got), $s->wallet->fresh()->balance);
    }

    public function test_split_bill_equal_shares_paid_and_settled(): void
    {
        $creator = $this->mk('client', '242061000141');
        $p1 = $this->mk('client', '242061000142', 20000);
        $p2 = $this->mk('client', '242061000143', 20000);
        $this->actingAs($creator, 'sanctum');
        $split = $this->postJson('/api/splits', ['title' => 'Dîner', 'total_amount' => 30000, 'mode' => 'equal', 'participants' => [['phone' => '242061000142'], ['phone' => '242061000143']]])->assertCreated()->json();
        $this->assertSame(20000, $split['pending_amount']);

        foreach ([$p1, $p2] as $p) {
            $this->actingAs($p, 'sanctum');
            $share = collect($this->getJson('/api/splits')->json('to_pay'))->firstWhere('status', 'pending');
            $this->postJson("/api/splits/shares/{$share['id']}/pay")->assertCreated();
        }
        $this->assertSame(20000, $creator->wallet->fresh()->balance);
        $this->assertSame('settled', \App\Models\BillSplit::find($split['id'])->status);
    }

    // ================================================================ Litiges, support, back-office

    public function test_dispute_resolved_with_refund_and_ticket(): void
    {
        $m = $this->mk('merchant', '242061000151');
        $c = $this->mk('client', '242061000152', 30000);
        $this->actingAs($c, 'sanctum');
        $tx = $this->postJson('/api/pay/merchant', ['merchant_code' => 'FPM-242061000151', 'source' => 'wallet', 'amount' => 10000])->json();
        $d = $this->postJson('/api/disputes', ['transaction_id' => $tx['id'], 'reason' => 'wrong_amount', 'description' => 'Facturé deux fois'])->assertCreated()->json();
        $this->postJson('/api/disputes', ['transaction_id' => $tx['id'], 'reason' => 'other'])->assertStatus(422);
        $this->postJson('/api/support/tickets', ['category' => 'information', 'subject' => 'Frais', 'message' => 'Quels frais ?'])->assertCreated();
        $this->getJson('/api/support/faq')->assertOk()->assertJsonStructure(['faq', 'channels']);

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson('/api/support/desk/disputes')->assertOk()->assertJsonPath('data.0.reason_label', 'Montant erroné');
        $this->postJson("/api/admin/disputes/{$d['id']}", ['action' => 'refund', 'amount' => 5000, 'resolution' => 'Doublon confirmé'])->assertOk()->assertJsonPath('status', 'resolved_refunded');
        $this->assertSame(25000, $c->wallet->fresh()->balance);
        $ticket = $this->getJson('/api/support/desk/tickets')->json('data.0');
        $this->postJson("/api/support/desk/tickets/{$ticket['id']}/reply", ['message' => 'Paiement marchand gratuit.'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $c->id)->where('type', 'support_reply')->exists());
    }

    public function test_admin_wallet_adjustment_is_audited_and_chain_intact(): void
    {
        $c = $this->mk('client', '242061000161', 1000);
        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson("/api/admin/users/{$c->id}/wallet/adjust", ['direction' => 'credit', 'amount' => 2500, 'reason' => 'Correction cash-in contesté'])->assertCreated();
        $this->postJson("/api/admin/users/{$c->id}/wallet/adjust", ['direction' => 'debit', 'amount' => 500, 'reason' => 'Correction erreur de saisie'])->assertCreated();
        $this->assertSame(3000, $c->wallet->fresh()->balance);
        $this->assertSame(2, AuditLog::where('action', 'wallet.adjust')->count());
        $this->getJson('/api/admin/audit-logs/verify')->assertOk()->assertJsonPath('intact', true);
        $this->assertTrue(AppNotification::where('audience', 'admin')->where('type', 'manual_intervention')->exists());
        $this->expectException(\LogicException::class);
        AuditLog::first()->delete();
    }

    public function test_degraded_mode_and_reconciliation(): void
    {
        $c = $this->mk('client', '242061000171', 10000);
        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson('/api/admin/settings/channels', ['channels' => ['peex_payout' => ['enabled' => false, 'message' => 'Incident PEEX en cours']]])->assertOk()->assertJsonPath('peex_payout.enabled', false);
        $this->getJson('/api/status')->assertJsonPath('channels.peex_payout.enabled', false);

        $this->actingAs($c, 'sanctum');
        $this->postJson('/api/pay/withdraw', ['amount' => 1000])->assertStatus(503)->assertJsonPath('message', 'Incident PEEX en cours');
        $this->assertSame(10000, $c->wallet->fresh()->balance);

        // Réconciliation : le solde initial sans écriture est signalé
        $this->actingAs($this->admin(), 'sanctum');
        $r = $this->postJson('/api/admin/reconciliation/run')->assertCreated();
        $this->assertGreaterThanOrEqual(1, $r->json('anomalies'));
        $this->assertSame([], $r->json('results.unbalanced_transactions'));
    }

    public function test_linked_accounts_bank_withdrawal_mini_programs_and_admin_tools(): void
    {
        $c = $this->mk('client', '242061000181', 100000);
        $m = $this->mk('merchant', '242061000182');
        $this->actingAs($c, 'sanctum');
        $this->postJson('/api/linked-accounts', ['type' => 'mobile_money', 'phone' => '242061000181'])->assertCreated()->assertJsonPath('is_default', true);
        $bank = $this->postJson('/api/linked-accounts', ['type' => 'bank', 'bank_name' => 'BGFI', 'account_holder' => 'Test', 'account_number' => 'CG39 3000 1234'])->assertCreated()->json();
        $this->getJson('/api/wallet/overview')->assertOk()->assertJsonCount(2, 'linked_accounts');
        $tx = $this->postJson('/api/pay/withdraw-bank', ['linked_account_id' => $bank['id'], 'amount' => 20000])->assertStatus(202)->json();
        $this->assertSame('awaiting_bank', $tx['stage']);

        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson("/api/admin/settlements/{$tx['id']}/complete", ['bank_ref' => 'VIR-1'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $c->id)->where('type', 'withdrawal_status')->exists());

        $this->postJson('/api/admin/mini-programs', ['merchant_id' => $m->merchant->id, 'name' => 'Billets Océan', 'category' => 'billetterie', 'entry_url' => 'https://billets.test', 'status' => 'approved'])->assertCreated();
        $this->postJson('/api/admin/commission-rules', ['operation' => 'cash_in', 'min_amount' => 0, 'max_amount' => 1000000, 'type' => 'fixed', 'value' => 250])->assertCreated();
        $this->getJson('/api/admin/commission-rules')->assertOk();
        $this->getJson('/api/admin/notifications')->assertOk();
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonStructure(['channels', 'limits', 'security']);
        $this->getJson('/api/admin/ecommerce')->assertOk();

        $this->actingAs($c, 'sanctum');
        $this->getJson('/api/mini-programs?category=billetterie')->assertOk()->assertJsonPath('programs.0.name', 'Billets Océan');
        $this->getJson('/api/kyc')->assertOk()->assertJsonPath('kyc_tier', 0);
    }
}

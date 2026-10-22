<?php

namespace Tests\Feature;

use App\Models\PeexRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Correctifs de sécurité du 22/10/2026. */
class SecurityHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
    }

    protected function client(array $extra = []): User
    {
        $u = User::create(['full_name' => 'CLIENT TEST', 'phone' => '242061112233', 'password' => Hash::make('4827'), 'status' => 'active'] + $extra);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        return $u;
    }

    public function test_account_locked_after_five_wrong_codes(): void
    {
        $this->client();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['phone' => '242061112233', 'password' => '000' . $i, 'profile' => 'client'])->assertStatus(401);
        }
        // Même le bon code est refusé pendant le verrouillage
        $this->postJson('/api/auth/login', ['phone' => '242061112233', 'password' => '4827', 'profile' => 'client'])->assertStatus(429)->assertJsonPath('code', 'login_locked');
    }

    public function test_new_device_otp_cannot_be_skipped_by_omitting_device_id(): void
    {
        config(['security.otp_on_new_device' => true]);
        $this->client();
        $this->postJson('/api/auth/login', ['phone' => '242061112233', 'password' => '4827', 'profile' => 'client', 'device_id' => 'phone-1'])->assertOk();
        $this->postJson('/api/auth/login', ['phone' => '242061112233', 'password' => '4827', 'profile' => 'client'])->assertStatus(202)->assertJsonPath('otp_required', true);
    }

    public function test_pin_reset_needs_id_number_while_sms_codes_are_shown_in_the_app(): void
    {
        config(['security.otp_debug' => true]);
        $this->client(['id_number' => 'OA1234567']);
        $code = $this->postJson('/api/auth/otp', ['phone' => '242061112233', 'purpose' => 'pin_reset'])->json('debug_code');
        $this->postJson('/api/auth/pin/reset', ['phone' => '242061112233', 'otp' => $code, 'new_pin' => '5931'])->assertStatus(422)->assertJsonPath('code', 'id_mismatch');
        $this->postJson('/api/auth/pin/reset', ['phone' => '242061112233', 'otp' => $code, 'new_pin' => '5931', 'id_number' => 'XX999'])->assertStatus(422);
        $this->postJson('/api/auth/pin/reset', ['phone' => '242061112233', 'otp' => $code, 'new_pin' => '5931', 'id_number' => 'oa 1234567'])->assertOk();

        // Compte sans numéro de pièce : passage par le support
        User::where('phone', '242061112233')->update(['id_number' => null]);
        Cache::flush();
        $this->travel(1)->minutes();
        $code = $this->postJson('/api/auth/otp', ['phone' => '242061112233', 'purpose' => 'pin_reset'])->json('debug_code');
        $this->postJson('/api/auth/pin/reset', ['phone' => '242061112233', 'otp' => $code, 'new_pin' => '7315'])->assertStatus(422)->assertJsonPath('code', 'support_required');
    }

    public function test_default_admin_password_refused(): void
    {
        $this->postJson('/api/auth/login', ['phone' => '242060000000', 'password' => 'ChangeMoi123!', 'profile' => 'super_admin'])->assertStatus(403)->assertJsonPath('code', 'default_password');
    }

    public function test_forged_peex_success_callback_is_not_applied(): void
    {
        $u = $this->client();
        $req = PeexRequest::create(['track_id' => 'FP-TEST-C1', 'service' => 'collect', 'status' => 'pending', 'amount' => 5000, 'currency' => 'XAF', 'phone' => '242061112233', 'country' => 'CG']);
        Http::fake(['*' => Http::response([])]); // PEEX ne connaît pas ce paiement
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('peex:peex_callback')])
            ->postJson('/api/webhooks/peex/collect', [['track_id' => 'FP-TEST-C1', 'status' => 'success']])->assertOk();
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertNull($req->fresh()->finalized_at);
    }
}

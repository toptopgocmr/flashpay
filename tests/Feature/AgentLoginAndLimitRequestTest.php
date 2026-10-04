<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AgentLoginAndLimitRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['security.otp_debug' => true, 'security.otp_on_new_device' => false]);
    }

    protected function admin(): User
    {
        return User::where('phone', '242060000000')->first();
    }

    public function test_agent_created_on_existing_number_can_login_with_admin_code(): void
    {
        // Le numéro a déjà un compte client (autre mot de passe)
        $u = User::create(['full_name' => 'Kloe', 'phone' => '242067621919', 'password' => 'ancien99']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);

        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson('/api/admin/agents', ['full_name' => 'Kloe', 'phone' => '067621919', 'password' => '5827', 'country' => 'CG', 'city' => 'Brazzaville'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', ['phone' => '+242067621919', 'password' => '5827', 'profile' => 'agent'])->assertOk();
        $this->postJson('/api/auth/login', ['phone' => '+242067621919', 'password' => '0000', 'profile' => 'agent'])->assertStatus(401);
        // Profil inexistant : message explicite
        $this->postJson('/api/auth/login', ['phone' => '+242067621919', 'password' => '5827', 'profile' => 'merchant'])->assertStatus(403)->assertJsonPath('code', 'wrong_profile');

        // Réinitialisation console : code à 4 chiffres -> connexion + PIN
        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson("/api/admin/users/{$u->id}/password", ['password' => '6394'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['phone' => '067621919', 'password' => '6394', 'profile' => 'agent'])->assertOk();
        $this->assertTrue(Hash::check('6394', $u->fresh()->pin_hash));
    }

    public function test_forgotten_code_resets_login_secret_and_pin_fallback(): void
    {
        $u = User::create(['full_name' => 'Awa', 'phone' => '242067601920', 'password' => 'secret123']);
        $u->assignRole('agent');
        $otp = $this->postJson('/api/auth/otp', ['phone' => '+242067601920', 'purpose' => 'pin_reset'])->json('debug_code');
        $this->postJson('/api/auth/pin/reset', ['phone' => '+242067601920', 'otp' => $otp, 'new_pin' => '4821'])->assertOk();
        $this->postJson('/api/auth/login', ['phone' => '+242067601920', 'password' => '4821', 'profile' => 'agent'])->assertOk();

        // Ancien parcours : seul le PIN avait changé -> le PIN est accepté et le mot de passe resynchronisé
        $u->forceFill(['password' => Hash::make('autre123'), 'pin_hash' => Hash::make('7395')])->save();
        $this->postJson('/api/auth/login', ['phone' => '+242067601920', 'password' => '7395', 'profile' => 'agent'])->assertOk();
        $this->assertTrue(Hash::check('7395', $u->fresh()->password));
    }

    public function test_client_limit_request_with_justification_approved_by_admin(): void
    {
        $c = User::create(['full_name' => 'Client Plafond', 'phone' => '242061234599', 'password' => 'secret123', 'kyc_tier' => 0]);
        $c->assignRole('client');
        Wallet::create(['user_id' => $c->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);

        $this->actingAs($c, 'sanctum');
        $this->postJson('/api/limits/requests', ['daily' => 100000, 'reason_type' => 'commerce', 'justification' => 'Je vends des pagnes au marché Total, achats de stock.'])->assertStatus(422); // inférieur à l'actuel
        $req = $this->post('/api/limits/requests', [
            'daily' => 1500000, 'monthly' => 5000000, 'reason_type' => 'commerce',
            'justification' => 'Je vends des pagnes au marché Total, achats de stock chaque semaine.',
            'file' => UploadedFile::fake()->image('patente.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('request');
        $this->postJson('/api/limits/requests', ['daily' => 2000000, 'reason_type' => 'autre', 'justification' => 'Deuxième demande pendant la première.'])->assertStatus(422);

        $this->actingAs($this->admin(), 'sanctum');
        $this->getJson('/api/admin/limit-requests')->assertOk()->assertJsonPath('data.0.id', $req['id']);
        $this->get("/api/admin/limit-requests/{$req['id']}/file")->assertOk();
        $this->postJson("/api/admin/limit-requests/{$req['id']}/review", ['decision' => 'approve', 'daily' => 1000000, 'until' => now()->addMonth()->toDateString()])->assertOk()->assertJsonPath('status', 'approved');

        $l = app(\App\Services\Compliance\LimitService::class)->limitsFor($c->fresh());
        $this->assertSame(1000000, $l['daily']);
        $this->assertSame(5000000, $l['monthly']);
        $this->assertTrue($l['custom']);
    }
}

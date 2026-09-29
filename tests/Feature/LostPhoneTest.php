<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** « Téléphone perdu » : blocage immédiat, connexion refusée, déblocage par le support. */
class LostPhoneTest extends TestCase
{
    public function test_lost_phone_blocks_login_until_support_unblocks(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $u = User::create(['full_name' => 'Awa', 'phone' => '242067601919', 'password' => 'secret123']);
        $u->assignRole('client');
        $login = ['phone' => '+242067601919', 'password' => 'secret123', 'profile' => 'client', 'device_id' => 'tel-1'];
        $token = $this->postJson('/api/auth/login', $login)->assertOk()->json('token');

        // Mauvais mot de passe : refusé, rien n'est bloqué
        $this->postJson('/api/auth/report-lost', ['phone' => '+242067601919', 'password' => 'faux'])->assertStatus(401);
        $this->assertNull($u->fresh()->lost_reported_at);

        // Déclaration depuis un autre téléphone
        $this->postJson('/api/auth/report-lost', ['phone' => '242067601919', 'password' => 'secret123'])->assertOk();
        $this->assertNotNull($u->fresh()->lost_reported_at);
        $this->assertSame(0, $u->fresh()->tokens()->count()); // sessions coupées

        // Plus aucune connexion (même avec le bon mot de passe)
        $this->postJson('/api/auth/login', $login)->assertStatus(423)->assertJsonPath('code', 'account_lost_blocked');

        // Déblocage par le support après vérification d'identité
        $this->actingAs(User::where('phone', '242060000000')->first(), 'sanctum');
        $this->postJson("/api/admin/users/{$u->id}/unblock", ['reason' => 'Identité vérifiée en agence'])->assertOk();
        $this->getJson("/api/admin/clients/{$u->id}")->assertOk()->assertJsonPath('lost_reported_at', null);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', $login + ['otp' => null])->assertSuccessful();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PinResetSimpleTest extends TestCase
{
    public function test_pin_reset_with_phone_code_and_new_pin_only(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['security.otp_debug' => true]);
        $u = User::create(['full_name' => 'Awa', 'phone' => '242067601919', 'password' => 'secret123', 'id_number' => 'CG123']);
        $u->assignRole('client');

        // Code invalide : refusé
        $this->postJson('/api/auth/otp', ['phone' => '+242067601919', 'purpose' => 'pin_reset'])->assertOk();
        $this->postJson('/api/auth/pin/reset', ['phone' => '+242067601919', 'otp' => '000000', 'new_pin' => '4821'])->assertStatus(422);

        // Numéro + code + nouveau PIN : suffit (pas de mot de passe, pas de pièce)
        $this->travel(31)->seconds(); // délai minimal entre deux envois de code
        $otp = $this->postJson('/api/auth/otp', ['phone' => '+242067601919', 'purpose' => 'pin_reset'])->json('debug_code');
        $this->postJson('/api/auth/pin/reset', ['phone' => '+242067601919', 'otp' => $otp, 'new_pin' => '4821'])->assertOk();
        $this->assertTrue(Hash::check('4821', $u->fresh()->pin_hash));

        // Le code ne peut pas être réutilisé
        $this->postJson('/api/auth/pin/reset', ['phone' => '+242067601919', 'otp' => $otp, 'new_pin' => '7777'])->assertStatus(422);
    }
}

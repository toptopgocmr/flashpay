<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Merchant;
use App\Models\MerchantCashier;
use App\Models\MerchantOutlet;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de démonstration — un par rôle de l'application mobile
 * (maquettes v2). Code secret commun : 2580 (sert aussi de PIN).
 *
 *   Client      +242 06 123 45 67
 *   Marchand    +242 06 234 56 78   (Boutique Jennifer)
 *   Agent       +242 06 345 67 89   (ou identifiant AG…)
 *   Sous-agent  +242 06 456 78 90
 *   Caissier    +242 06 567 89 01   (caisse de la Boutique Jennifer)
 *
 * Idempotent : ne recrée rien et ne touche pas aux soldes si le compte existe.
 * Ignoré en production.
 */
class DemoAccountsSeeder extends Seeder
{
    public const CODE = '2580';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $client = $this->user('242061234567', 'Kloe Makaya', 'client', 45000, ['kyc_status' => 'verified', 'kyc_tier' => 1]);

        $merchantUser = $this->user('242062345678', 'Jennifer Bakote', 'merchant', 612500, ['kyc_status' => 'verified']);
        $merchant = Merchant::firstOrCreate(['user_id' => $merchantUser->id], [
            'business_name' => 'Boutique Jennifer',
            'business_category' => 'Boutique / habillement',
            'country' => 'CG',
            'city' => 'Brazzaville',
            'address' => 'Poto-Poto, avenue de la Paix',
            'settlement_phone' => $merchantUser->phone,
            'qr_code_token' => 'FPM-DEMOJENNIFER',
            'validation_status' => 'approved',
            'validated_at' => now(),
        ]);
        $outlet = MerchantOutlet::firstOrCreate(['merchant_id' => $merchant->id, 'name' => 'Boutique Poto-Poto'], [
            'address' => 'Poto-Poto, Brazzaville',
            'qr_code_token' => 'FPO-DEMOPOTOPOTO',
            'status' => 'active',
        ]);

        $agentUser = $this->user('242063456789', 'Agent Poto-Poto', 'agent', 380000, ['kyc_status' => 'verified']);
        $agent = Agent::firstOrCreate(['user_id' => $agentUser->id], [
            'country' => 'CG', 'city' => 'Brazzaville', 'zone' => 'Poto-Poto',
            'validation_status' => 'approved', 'validated_at' => now(), 'is_super_agent' => true,
        ]);

        $subUser = $this->user('242064567890', 'Sous-agent Moungali', 'agent', 150000, ['kyc_status' => 'verified']);
        Agent::firstOrCreate(['user_id' => $subUser->id], [
            'country' => 'CG', 'city' => 'Brazzaville', 'zone' => 'Moungali',
            'validation_status' => 'approved', 'validated_at' => now(), 'parent_agent_id' => $agent->id,
        ]);

        $cashierUser = $this->user('242065678901', 'Grâce Caisse', 'cashier', null, ['kyc_status' => 'verified']);
        MerchantCashier::firstOrCreate(['user_id' => $cashierUser->id], [
            'merchant_id' => $merchant->id, 'outlet_id' => $outlet->id, 'status' => 'active',
        ]);

        $this->command?->info('Comptes démo prêts — code secret ' . self::CODE . ' (client 06 123 45 67, marchand 06 234 56 78, agent 06 345 67 89, sous-agent 06 456 78 90, caissier 06 567 89 01).');
    }

    protected function user(string $phone, string $name, string $role, ?int $balance, array $extra = []): User
    {
        $user = User::where('phone', $phone)->first();
        if (! $user) {
            $user = User::create([
                'full_name' => $name,
                'phone' => $phone,
                'password' => Hash::make(self::CODE),
                'status' => 'active',
            ] + $extra);
            $user->forceFill(['pin_hash' => Hash::make(self::CODE), 'pin_changed_at' => now(), 'phone_verified_at' => now()])->save();
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        if ($balance !== null && ! $user->wallet) {
            Wallet::create(['user_id' => $user->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        }
        return $user->fresh();
    }
}

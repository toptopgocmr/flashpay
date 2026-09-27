<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        // Grille tarifaire style Wave + taux de change indicatifs
        $this->call([TariffSeeder::class, ExchangeRateSeeder::class, CommissionRuleSeeder::class]);

        // Super Admin par défaut — À CHANGER en production
        $admin = User::firstOrCreate(
            ['phone' => '242060000000'],
            ['full_name' => 'Super Admin FlashPay', 'password' => Hash::make('ChangeMoi123!'), 'status' => 'active']
        );
        if (! $admin->hasRole('super_admin')) {
            $admin->assignRole('super_admin');
        }
        $wallet = Wallet::firstOrCreate(['user_id' => $admin->id], ['balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);

        // Sandbox PEEX : float de test sur le wallet admin pour les tests de décaissement
        if (config('flashpay.peex.sandbox') && app()->environment('local') && $wallet->balance == 0) {
            $wallet->update(['balance' => 1_000_000]);
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Crée les 5 rôles métier du cahier des charges (§2).
 * Exécuter : php artisan db:seed --class=RolesSeeder
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['client', 'merchant', 'agent', 'super_admin', 'support', 'cashier'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}

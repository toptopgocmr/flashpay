<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 *   php artisan officepay:admin-password                     mot de passe du super admin 242060000000 (demandé)
 *   php artisan officepay:admin-password 242061234567        d'un autre compte console
 * 12 caractères minimum, lettres + chiffres. Toutes les sessions du compte sont fermées.
 */
class AdminPasswordCommand extends Command
{
    protected $signature = 'officepay:admin-password {phone=242060000000 : numéro du compte console}';

    protected $description = 'Change le mot de passe d\'un compte de la console (super admin / support)';

    public function handle(): int
    {
        $user = User::where('phone', preg_replace('/\D/', '', $this->argument('phone')))->first();
        if (! $user || ! $user->hasAnyRole(['super_admin', 'support'])) {
            $this->error('Aucun compte console avec ce numéro.');
            return self::FAILURE;
        }
        $pass = (string) $this->secret('Nouveau mot de passe (12 caractères min., lettres et chiffres)');
        if (strlen($pass) < 12 || ! preg_match('/[A-Za-z]/', $pass) || ! preg_match('/\d/', $pass) || $pass === 'ChangeMoi123!') {
            $this->error('Mot de passe trop faible : 12 caractères minimum, avec des lettres et des chiffres.');
            return self::FAILURE;
        }
        if ($pass !== (string) $this->secret('Confirmez le mot de passe')) {
            $this->error('Les deux saisies ne correspondent pas.');
            return self::FAILURE;
        }
        $user->forceFill(['password' => Hash::make($pass)])->save();
        $user->tokens()->delete();
        \App\Support\Audit::log('security.admin_password_changed', $user, [], null);
        $this->info("Mot de passe de {$user->full_name} changé ; toutes ses sessions sont fermées.");
        return self::SUCCESS;
    }
}

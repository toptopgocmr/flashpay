<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdminPasswordCommand extends Command
{
    protected $signature = 'officepay:admin-password {phone=242060000000 : numéro du compte console}';

    protected $description = "Change le mot de passe d'un compte de la console (super admin / support)";

    private const FORBIDDEN = ['ChangeMoi123!'];

    public function handle(): int
    {
        $phone = preg_replace('/\D+/', '', (string) $this->argument('phone'));

        $column = collect(['phone', 'phone_number', 'msisdn', 'telephone', 'mobile'])
            ->first(fn ($c) => Schema::hasColumn('users', $c));

        if (! $column) {
            $this->error('Colonne du numéro introuvable dans la table users.');
            return self::FAILURE;
        }

        $user = DB::table('users')
            ->where(function ($q) use ($column, $phone) {
                $q->where($column, $phone)->orWhere($column, '+'.$phone);
            })
            ->first();

        if (! $user) {
            $this->error("Aucun compte avec le numéro {$phone}.");
            return self::FAILURE;
        }

        $password = (string) env('OFFICEPAY_ADMIN_PASSWORD', '');

        if ($password === '') {
            if (! $this->input->isInteractive()) {
                $this->warn('OFFICEPAY_ADMIN_PASSWORD vide : mot de passe inchangé.');
                return self::SUCCESS;
            }
            $password = (string) $this->secret('Nouveau mot de passe (12 caractères minimum)');
            if ($password !== (string) $this->secret('Confirmez le mot de passe')) {
                $this->error('Les deux saisies sont différentes.');
                return self::FAILURE;
            }
        }

        if (strlen($password) < 12 || in_array($password, self::FORBIDDEN, true)) {
            $this->error('Mot de passe refusé : 12 caractères minimum et différent du mot de passe par défaut.');
            return self::FAILURE;
        }

        $update = ['password' => Hash::make($password)];
        if (Schema::hasColumn('users', 'updated_at')) {
            $update['updated_at'] = now();
        }
        foreach (['login_attempts' => 0, 'failed_login_attempts' => 0, 'locked_until' => null] as $col => $val) {
            if (Schema::hasColumn('users', $col)) {
                $update[$col] = $val;
            }
        }

        DB::table('users')->where('id', $user->id)->update($update);

        $this->info("Mot de passe mis à jour pour le compte {$phone}.");
        return self::SUCCESS;
    }
}
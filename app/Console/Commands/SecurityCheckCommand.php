<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 *   php artisan officepay:security-check
 * Vérifie les réglages de production à risque (à lancer sur Railway après chaque déploiement).
 * Code de sortie 1 s'il reste un point CRITIQUE.
 */
class SecurityCheckCommand extends Command
{
    protected $signature = 'officepay:security-check';

    protected $description = 'Contrôle des réglages de sécurité de production';

    public function handle(): int
    {
        $rows = [];
        $add = function (string $level, string $what, string $fix) use (&$rows) {
            $rows[] = [$level, $what, $fix];
        };

        if (! app()->environment('production')) {
            $add('CRITIQUE', 'APP_ENV = ' . app()->environment() . ' (comptes démo, page carte simulée possibles)', 'APP_ENV=production');
        }
        if (config('app.debug')) {
            $add('CRITIQUE', 'APP_DEBUG actif : les erreurs affichent le code et la configuration', 'APP_DEBUG=false');
        }
        if (config('security.otp_debug')) {
            $add('ÉLEVÉ', 'Codes OTP renvoyés à l\'app (FLASHPAY_OTP_DEBUG) : le code SMS ne protège plus rien', 'Brancher les SMS puis FLASHPAY_OTP_DEBUG=false (en attendant, la pièce d\'identité est exigée pour « Code oublié »)');
        }
        if (! config('security.require_otp_on_register')) {
            $add('MOYEN', 'Inscription sans vérification du numéro (OTP)', 'FLASHPAY_REQUIRE_OTP=true');
        }
        if (config('flashpay.peex.callback_username') === 'peex' || config('flashpay.peex.callback_password') === 'peex_callback') {
            $add('ÉLEVÉ', 'Identifiants des callbacks PEEX par défaut (publics dans le code)', 'PEEX_CALLBACK_USERNAME / PEEX_CALLBACK_PASSWORD forts, communiqués à PEEX');
        }
        foreach (['DIGITWACE' => 'flashpay.digitwace.webhook_secret', 'FINCRA' => 'flashpay.fincra.webhook_secret', 'MAISHAPAY' => 'flashpay.maishapay.webhook_secret'] as $p => $key) {
            if (! config($key)) {
                $add('INFO', "Webhook {$p} non signé ({$p}_WEBHOOK_SECRET vide) — statut toujours relu via l'API", "Renseigner {$p}_WEBHOOK_SECRET");
            }
        }
        if (in_array('*', (array) config('cors.allowed_origins', []), true)) {
            $add('MOYEN', 'CORS ouvert à tous les sites (*)', 'CORS_ALLOWED_ORIGINS=https://votre-domaine');
        }
        if (config('payment_methods.card_driver') === 'sandbox') {
            $add(\App\Services\Payments\CardPaymentService::sandboxForbidden() ? 'INFO' : 'CRITIQUE', 'FLASHPAY_CARD_DRIVER=sandbox (carte simulée)' . (\App\Services\Payments\CardPaymentService::sandboxForbidden() ? ' — bloquée en production' : ''), 'FLASHPAY_CARD_DRIVER=wacepay ou maishapay');
        }
        if (config('flashpay.webrtc.turn_password') === 'openrelayproject') {
            $add('INFO', 'Serveur TURN public de démonstration pour les appels', 'Serveur TURN privé (WEBRTC_TURN_*)');
        }
        try {
            foreach (User::role(['super_admin', 'support'])->get() as $u) {
                if (Hash::check('ChangeMoi123!', $u->password)) {
                    $add('CRITIQUE', "Compte console {$u->phone} avec le mot de passe par défaut", 'php artisan officepay:admin-password ' . $u->phone);
                }
            }
            $demo = User::whereIn('phone', ['242061234567', '242062345678', '242063456789', '242064567890', '242065678901'])->count();
            if ($demo && app()->environment('production')) {
                $add('ÉLEVÉ', "{$demo} compte(s) de démonstration (code 2580) en production", 'Les désactiver dans la console (Clients / Agents / Marchands)');
            }
        } catch (\Throwable $e) {
            $add('INFO', 'Comptes non vérifiés : ' . $e->getMessage(), '');
        }
        if (! config('flashpay.kyc.id_check_on_register', true)) {
            $add('MOYEN', 'Contrôle de la pièce à l\'inscription désactivé', 'FLASHPAY_ID_CHECK_ON_REGISTER=true (après diffusion du nouvel APK)');
        }

        if (! $rows) {
            $this->info('✔ Aucun réglage à risque détecté.');
            return self::SUCCESS;
        }
        $this->table(['Niveau', 'Constat', 'Correction'], $rows);
        return collect($rows)->contains(fn ($r) => $r[0] === 'CRITIQUE') ? self::FAILURE : self::SUCCESS;
    }
}

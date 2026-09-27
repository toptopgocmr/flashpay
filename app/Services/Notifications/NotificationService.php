<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de notification centralisé (§11.5) : appelé par le PaymentGateway
 * à chaque écriture / changement de statut. Enregistre la notification
 * (historique consultable), puis envoie push et SMS APRÈS le commit de la
 * transaction principale, sans jamais la bloquer (erreurs journalisées).
 */
class NotificationService
{
    public function __construct(protected SmsSender $sms, protected PushSender $push)
    {
    }

    /**
     * @param array{severity?:string, data?:array, sound?:bool, sms?:bool|string} $opt
     *        sms: true = même texte par SMS ; string = texte SMS spécifique
     */
    public function toUser(?User $user, string $type, string $title, ?string $body = null, array $opt = []): ?AppNotification
    {
        if (! $user) {
            return null;
        }
        try {
            $n = AppNotification::create([
                'user_id' => $user->id,
                'audience' => 'user',
                'type' => $type,
                'severity' => $opt['severity'] ?? 'info',
                'title' => mb_substr($title, 0, 150),
                'body' => $body,
                'data' => $opt['data'] ?? null,
                'sound' => (bool) ($opt['sound'] ?? false),
            ]);
        } catch (\Throwable $e) {
            Log::error('Notification non enregistrée : ' . $e->getMessage());
            return null;
        }

        $sms = $opt['sms'] ?? in_array($type, config('notifications.sms_events', []), true);
        $this->afterCommit(function () use ($n, $user, $sms, $title, $body) {
            if ($this->push->send($n)) {
                $n->forceFill(['push_sent_at' => now()])->saveQuietly();
            }
            if ($sms) {
                $text = is_string($sms) ? $sms : trim($title . '. ' . $body);
                if ($this->sms->send($user->phone, 'FlashPay: ' . $text)) {
                    $n->forceFill(['sms_sent_at' => now()])->saveQuietly();
                }
            }
        });

        return $n;
    }

    /** Centre de notifications de la console d'administration (§11.4). */
    public function toAdmins(string $type, string $title, ?string $body = null, array $opt = []): ?AppNotification
    {
        try {
            $n = AppNotification::create([
                'user_id' => null,
                'audience' => 'admin',
                'type' => $type,
                'severity' => $opt['severity'] ?? 'info',
                'title' => mb_substr($title, 0, 150),
                'body' => $body,
                'data' => $opt['data'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Notification admin non enregistrée : ' . $e->getMessage());
            return null;
        }

        // Relais SMS des événements critiques (fraude, incident technique)
        if (($opt['severity'] ?? null) === 'critical' && ($phone = config('notifications.admin_critical_sms'))) {
            $this->afterCommit(fn () => $this->sms->send($phone, "FlashPay ALERTE: {$title}"));
        }

        return $n;
    }

    /** SMS seul (OTP) — jamais stocké en clair. */
    public function sms(string $phone, string $text): bool
    {
        return $this->sms->send($phone, $text);
    }

    protected function afterCommit(callable $fn): void
    {
        $run = function () use ($fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::warning('Envoi de notification échoué : ' . $e->getMessage());
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($run) : $run();
    }
}

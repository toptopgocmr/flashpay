<?php

namespace App\Services\Compliance;

use App\Exceptions\BusinessException;
use App\Models\FraudAlert;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Audit;

/**
 * Détection de fraude (§4.5) : vélocité anormale, seuil LCB-FT,
 * score de risque par profil et blocage temporaire du compte.
 */
class FraudService
{
    public function __construct(protected NotificationService $notify)
    {
    }

    public function assertNotBlocked(User $user): void
    {
        if ($user->blocked_until && $user->blocked_until->isFuture()) {
            throw new BusinessException('Compte temporairement bloqué par la sécurité FlashPay jusqu\'à ' . $user->blocked_until->format('d/m H:i') . '. Contactez le support.', 'account_temporarily_blocked', 423);
        }
    }

    /** Évalue une opération sortante avant exécution. */
    public function evaluate(User $user, int $amount, string $type, ?string $currency = null): void
    {
        $this->assertNotBlocked($user);
        $cfg = config('limits.fraud');

        if ($user->limitProfile() === 'client') {
            $recent = Transaction::where('initiated_by', $user->id)
                ->where('created_at', '>=', now()->subMinutes($cfg['velocity_window_minutes']))->count();
            if ($recent >= $cfg['velocity_max_operations']) {
                $this->block($user, 'velocity', $cfg['block_score'], ['operations' => $recent, 'window_minutes' => $cfg['velocity_window_minutes']]);
            }
        }

        if ($amount >= config('limits.aml_report_threshold')) {
            $this->alert($user, 'aml_threshold', 40, ['amount' => $amount, 'currency' => $currency, 'type' => $type], 'warning');
        }
    }

    public function alert(?User $user, string $rule, int $score, array $details = [], string $severity = 'warning', ?Transaction $tx = null): FraudAlert
    {
        $a = FraudAlert::create(['user_id' => $user?->id, 'transaction_id' => $tx?->id, 'rule' => $rule, 'score' => $score, 'details' => $details, 'status' => 'open']);
        if ($user) {
            $user->forceFill(['risk_score' => min(100, $user->risk_score + (int) ($score / 4))])->save();
        }
        $this->notify->toAdmins('fraud_alert', 'Alerte anti-fraude : ' . $rule, ($user ? "{$user->full_name} ({$user->phone})" : '') . ' — score ' . $score, ['severity' => $severity, 'data' => ['alert_id' => $a->id, 'user_id' => $user?->id]]);
        return $a;
    }

    protected function block(User $user, string $rule, int $score, array $details): never
    {
        $until = now()->addMinutes(config('limits.fraud.block_minutes', 60));
        $user->forceFill(['blocked_until' => $until])->save();
        $this->alert($user, $rule, $score, $details + ['blocked_until' => $until->toIso8601String()], 'critical');
        Audit::log('fraud.temporary_block', $user, ['rule' => $rule] + $details, null);
        $this->notify->toUser($user, 'account_blocked', 'Compte temporairement bloqué', 'Activité inhabituelle détectée. Contactez le support si besoin.', ['severity' => 'critical']);
        throw new BusinessException('Activité inhabituelle détectée : votre compte est temporairement bloqué. Contactez le support FlashPay.', 'account_temporarily_blocked', 423);
    }
}

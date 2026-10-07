<?php

namespace App\Services\Compliance;

use App\Exceptions\BusinessException;
use App\Models\FraudAlert;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notifications\NotificationService;
use App\Services\Ops\PlatformSettings;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;

/**
 * Détection de fraude (§4.5) — règles évaluées AVANT chaque opération sortante.
 *
 * Chaque règle a : enabled, action (alert = alerte seule | block = blocage
 * temporaire du compte + opération refusée), score (0-100) et ses seuils.
 * Valeurs par défaut ci-dessous (DEFAULTS, surchargées par les variables
 * d'environnement historiques), modifiables dans Console › Anti-fraude ›
 * Règles (table platform_settings, clé "fraud_rules").
 */
class FraudService
{
    /** Opérations qui font sortir de l'argent (seules évaluées). */
    public const OUTGOING_TYPES = [
        'p2p', 'transfer', 'withdrawal', 'qr_payment', 'nfc_payment', 'collection', 'merchant_payment',
        'cash_pickup', 'cash_out', 'bank_transfer', 'gift', 'split_payment', 'bill_payment',
    ];

    public const RULES = [
        'velocity' => 'Vélocité anormale',
        'aml_threshold' => 'Seuil LCB-FT dépassé',
        'structuring' => 'Fractionnement sous le seuil LCB-FT',
        'new_beneficiaries' => 'Trop de nouveaux bénéficiaires',
        'new_device_large' => 'Nouvel appareil + gros montant',
        'pin_failures' => 'Échecs de PIN puis opération',
        'new_account_large' => 'Compte récent + gros montant',
        'card_mismatch' => 'Carte non liée utilisée',
    ];

    public function __construct(
        protected NotificationService $notify,
        protected PlatformSettings $settings,
    ) {
    }

    // ------------------------------------------------------------------ Réglages

    public static function defaults(): array
    {
        $f = config('limits.fraud', []);

        return [
            'block_minutes' => (int) ($f['block_minutes'] ?? 60),
            'rules' => [
                'velocity' => ['enabled' => true, 'action' => 'block', 'score' => (int) ($f['block_score'] ?? 80),
                    'window_minutes' => (int) ($f['velocity_window_minutes'] ?? 10), 'max_operations' => (int) ($f['velocity_max_operations'] ?? 10)],
                'aml_threshold' => ['enabled' => true, 'action' => 'alert', 'score' => 40,
                    'amount' => (int) config('limits.aml_report_threshold', 5000000)],
                'structuring' => ['enabled' => true, 'action' => 'alert', 'score' => 60,
                    'hours' => 24, 'min_operations' => 3, 'near_percent' => 50],
                'new_beneficiaries' => ['enabled' => true, 'action' => 'alert', 'score' => 40,
                    'window_minutes' => 60, 'max_beneficiaries' => 5],
                'new_device_large' => ['enabled' => true, 'action' => 'alert', 'score' => 50,
                    'device_minutes' => 120, 'amount' => 200000],
                'pin_failures' => ['enabled' => true, 'action' => 'alert', 'score' => 45,
                    'failures' => 3, 'window_minutes' => 30],
                'new_account_large' => ['enabled' => true, 'action' => 'alert', 'score' => 40,
                    'account_days' => 7, 'amount' => 300000],
                'card_mismatch' => ['enabled' => true, 'action' => 'alert', 'score' => 50],
            ],
        ];
    }

    /** Réglages effectifs : défauts + surcharges de la console. */
    public function config(): array
    {
        $def = self::defaults();
        $saved = $this->settings->get('fraud_rules', []) ?: [];
        $out = ['block_minutes' => (int) ($saved['block_minutes'] ?? $def['block_minutes']), 'rules' => []];
        foreach ($def['rules'] as $k => $r) {
            $out['rules'][$k] = array_merge($r, array_intersect_key((array) ($saved['rules'][$k] ?? []), $r));
        }

        return $out;
    }

    protected function rule(string $key): ?array
    {
        $r = $this->config()['rules'][$key] ?? null;

        return $r && ! empty($r['enabled']) ? $r : null;
    }

    // ------------------------------------------------------------------ Contrôles

    public function assertNotBlocked(User $user): void
    {
        if ($user->blocked_until && $user->blocked_until->isFuture()) {
            throw new BusinessException('Compte temporairement bloqué par la sécurité FlashPay jusqu\'à ' . $user->blocked_until->format('d/m H:i') . '. Contactez le support.', 'account_temporarily_blocked', 423);
        }
    }

    /**
     * Évalue une opération sortante avant exécution.
     * @param array{destination?:?string} $ctx
     */
    public function evaluate(User $user, int $amount, string $type, ?string $currency = null, array $ctx = []): void
    {
        $this->assertNotBlocked($user);
        $client = $user->limitProfile() === 'client';
        $base = ['amount' => $amount, 'currency' => $currency, 'type' => $type];

        // 1. Vélocité : trop d'opérations en peu de temps (clients)
        if ($client && ($r = $this->rule('velocity'))) {
            $recent = Transaction::where('initiated_by', $user->id)
                ->where('created_at', '>=', now()->subMinutes($r['window_minutes']))->count();
            if ($recent >= $r['max_operations']) {
                $this->hit($user, 'velocity', $r, ['operations' => $recent, 'window_minutes' => $r['window_minutes']] + $base);
            }
        }

        // 2. Seuil LCB-FT : opération unique au-dessus du seuil
        if ($r = $this->rule('aml_threshold')) {
            if ($amount >= $r['amount']) {
                $this->hit($user, 'aml_threshold', $r, $base + ['threshold' => $r['amount']], dedup: false);
            }
        }

        if (! $client) {
            return;
        }

        $out = fn () => Transaction::where('initiated_by', $user->id)
            ->whereIn('type', self::OUTGOING_TYPES)->where('status', '!=', 'failed');

        // 3. Fractionnement : plusieurs opérations « juste sous le seuil » dont le cumul le dépasse
        if (($r = $this->rule('structuring')) && ($aml = $this->config()['rules']['aml_threshold']['amount'] ?? 0) > 0 && $amount < $aml) {
            $floor = (int) ($aml * $r['near_percent'] / 100);
            $since = now()->subHours($r['hours']);
            $near = $out()->where('created_at', '>=', $since)->where('amount', '>=', $floor)->where('amount', '<', $aml);
            $count = (clone $near)->count() + ($amount >= $floor ? 1 : 0);
            $total = (int) (clone $near)->sum('amount') + ($amount >= $floor ? $amount : 0);
            if ($count >= $r['min_operations'] && $total >= $aml) {
                $this->hit($user, 'structuring', $r, $base + ['operations' => $count, 'cumul' => $total, 'threshold' => $aml, 'hours' => $r['hours']]);
            }
        }

        // 4. Nombreux bénéficiaires différents en peu de temps
        if ($r = $this->rule('new_beneficiaries')) {
            $dests = $out()->where('created_at', '>=', now()->subMinutes($r['window_minutes']))
                ->whereNotNull('destination_account')->distinct()->pluck('destination_account')->all();
            if (! empty($ctx['destination'])) {
                $dests[] = $ctx['destination'];
            }
            $n = count(array_unique($dests));
            if ($n >= $r['max_beneficiaries']) {
                $this->hit($user, 'new_beneficiaries', $r, $base + ['beneficiaries' => $n, 'window_minutes' => $r['window_minutes']]);
            }
        }

        // 5. Nouvel appareil connecté récemment + gros montant
        if (($r = $this->rule('new_device_large')) && $amount >= $r['amount']) {
            $dev = UserDevice::where('user_id', $user->id)->whereNull('revoked_at')->latest('created_at')->first();
            $others = UserDevice::where('user_id', $user->id)->count();
            if ($dev && $others > 1 && $dev->created_at && $dev->created_at->gt(now()->subMinutes($r['device_minutes']))) {
                $this->hit($user, 'new_device_large', $r, $base + ['device' => $dev->name ?? $dev->device_id ?? null, 'device_since' => $dev->created_at->toIso8601String()]);
            }
        }

        // 6. Plusieurs PIN erronés récemment puis opération
        if ($r = $this->rule('pin_failures')) {
            $fails = (int) Cache::get(self::pinKey($user->id), 0);
            if ($fails >= $r['failures']) {
                $this->hit($user, 'pin_failures', $r, $base + ['pin_failures' => $fails, 'window_minutes' => $r['window_minutes']]);
            }
        }

        // 7. Compte récent + gros montant
        if (($r = $this->rule('new_account_large')) && $amount >= $r['amount'] && $user->created_at
            && $user->created_at->gt(now()->subDays($r['account_days']))) {
            $this->hit($user, 'new_account_large', $r, $base + ['account_created' => $user->created_at->toDateString(), 'account_days' => $r['account_days']]);
        }
    }

    /** Appelé par PinService à chaque PIN erroné. */
    public static function recordPinFailure(int $userId): void
    {
        $window = (int) (app(self::class)->config()['rules']['pin_failures']['window_minutes'] ?? 30);
        $key = self::pinKey($userId);
        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addMinutes($window));
    }

    protected static function pinKey(int $userId): string
    {
        return "fraud:pin_failures:{$userId}";
    }

    /** Recharge par carte avec une autre carte que la carte liée. */
    public function cardMismatch(User $user, Transaction $tx, string $used, ?string $expected): void
    {
        if ($r = $this->rule('card_mismatch')) {
            $details = ['card_used' => '•••• ' . $used, 'card_linked' => $expected ? '•••• ' . $expected : null, 'amount' => (int) $tx->amount, 'currency' => $tx->currency];
            if ($r['action'] === 'block') {
                $until = now()->addMinutes($this->config()['block_minutes']);
                $user->forceFill(['blocked_until' => $until])->save();
                $details['blocked_until'] = $until->toIso8601String();
            }
            $this->alert($user, 'card_mismatch', $r['score'], $details, 'warning', $tx);
        }
    }

    // ------------------------------------------------------------------ Alertes / blocage

    /** Règle déclenchée : alerte (au plus une toutes les 6 h par règle et par compte) ou blocage. */
    protected function hit(User $user, string $rule, array $r, array $details, bool $dedup = true): void
    {
        if (($r['action'] ?? 'alert') === 'block') {
            $this->block($user, $rule, (int) $r['score'], $details);
        }
        if ($dedup && ! Cache::add("fraud:dedup:{$rule}:{$user->id}", 1, now()->addHours(6))) {
            return;
        }
        $this->alert($user, $rule, (int) $r['score'], $details, $r['score'] >= 60 ? 'critical' : 'warning');
    }

    public function alert(?User $user, string $rule, int $score, array $details = [], string $severity = 'warning', ?Transaction $tx = null): FraudAlert
    {
        $a = FraudAlert::create(['user_id' => $user?->id, 'transaction_id' => $tx?->id, 'rule' => $rule, 'score' => $score, 'details' => $details, 'status' => 'open']);
        if ($user) {
            $user->forceFill(['risk_score' => min(100, $user->risk_score + (int) ($score / 4))])->save();
        }
        $label = self::RULES[$rule] ?? $rule;
        $this->notify->toAdmins('fraud_alert', 'Alerte anti-fraude : ' . $label, ($user ? "{$user->full_name} ({$user->phone})" : '') . ' — score ' . $score, ['severity' => $severity, 'data' => ['alert_id' => $a->id, 'user_id' => $user?->id]]);

        return $a;
    }

    protected function block(User $user, string $rule, int $score, array $details): never
    {
        $until = now()->addMinutes($this->config()['block_minutes']);
        $user->forceFill(['blocked_until' => $until])->save();
        $this->alert($user, $rule, $score, $details + ['blocked_until' => $until->toIso8601String()], 'critical');
        Audit::log('fraud.temporary_block', $user, ['rule' => $rule] + $details, null);
        $this->notify->toUser($user, 'account_blocked', 'Compte temporairement bloqué', 'Activité inhabituelle détectée. Contactez le support si besoin.', ['severity' => 'critical']);
        throw new BusinessException('Activité inhabituelle détectée : votre compte est temporairement bloqué. Contactez le support FlashPay.', 'account_temporarily_blocked', 423);
    }
}

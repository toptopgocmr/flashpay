<?php

namespace App\Services\Compliance;

use App\Exceptions\BusinessException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Ops\PlatformSettings;

/**
 * Plafonds par palier KYC (§12) — contrôle BLOQUANT avant toute opération.
 * Le cumul porte sur les débits du wallet (opérations réussies ou en cours).
 */
class LimitService
{
    /** Opérations non comptées dans les cumuls (corrections, remboursements…) */
    public const EXEMPT_TYPES = ['refund', 'adjustment', 'float_topup', 'gift_refund'];

    public function __construct(protected PlatformSettings $settings)
    {
    }

    public function limitsFor(User $user): ?array
    {
        $profile = $user->limitProfile();
        if ($profile === 'internal') {
            return null;
        }
        $cfg = config('limits');
        $override = $this->settings->get('limits', []);
        if ($profile === 'client') {
            $tier = min(2, max(0, (int) $user->kyc_tier));
            return array_merge($cfg['client'][$tier], $override['client'][$tier] ?? [], ['tier' => $tier, 'profile' => 'client']);
        }
        return array_merge($cfg[$profile], $override[$profile] ?? [], ['tier' => null, 'profile' => $profile]);
    }

    public function assertOutgoing(User $owner, Wallet $wallet, int $amount, ?string $scope = null, ?string $type = null): void
    {
        if (! config('limits.enabled') || in_array($type, self::EXEMPT_TYPES, true)) {
            return;
        }
        $l = $this->limitsFor($owner);
        if (! $l) {
            return;
        }
        $cur = $wallet->currency;
        $hint = $l['profile'] === 'client' && $l['tier'] < 2 ? ' Complétez votre KYC pour relever vos plafonds.' : '';

        if ($l['profile'] === 'client' && $scope === 'international' && empty($l['international'])) {
            throw new BusinessException('Les envois internationaux nécessitent un KYC complet (pièce d\'identité + selfie).', 'kyc_required', 422, ['tier' => $l['tier']]);
        }
        if ($l['per_operation'] && $amount > $l['per_operation']) {
            throw new BusinessException('Montant supérieur à votre plafond par opération (' . $this->fmt($l['per_operation'], $cur) . ').' . $hint, 'limit_exceeded', 422, ['limit' => 'per_operation', 'value' => $l['per_operation']]);
        }
        $usage = $this->usage($wallet);
        if ($l['daily'] && $usage['daily'] + $amount > $l['daily']) {
            throw new BusinessException('Plafond journalier atteint : il vous reste ' . $this->fmt(max(0, $l['daily'] - $usage['daily']), $cur) . ' aujourd\'hui.' . $hint, 'limit_exceeded', 422, ['limit' => 'daily', 'value' => $l['daily'], 'used' => $usage['daily']]);
        }
        if ($l['monthly'] && $usage['monthly'] + $amount > $l['monthly']) {
            throw new BusinessException('Plafond mensuel atteint : il vous reste ' . $this->fmt(max(0, $l['monthly'] - $usage['monthly']), $cur) . ' ce mois-ci.' . $hint, 'limit_exceeded', 422, ['limit' => 'monthly', 'value' => $l['monthly'], 'used' => $usage['monthly']]);
        }
    }

    /** Solde maximal du wallet bénéficiaire (clients uniquement). */
    public function assertIncoming(Wallet $wallet, int $amount, ?string $type = null): void
    {
        if (! config('limits.enabled') || in_array($type, self::EXEMPT_TYPES, true) || ! $wallet->user) {
            return;
        }
        $l = $this->limitsFor($wallet->user);
        if (! $l || empty($l['max_balance'])) {
            return;
        }
        if ($wallet->balance + $amount > $l['max_balance']) {
            throw new BusinessException('Opération impossible : le wallet du bénéficiaire dépasserait son solde maximal autorisé (' . $this->fmt($l['max_balance'], $wallet->currency) . ').', 'balance_limit', 422);
        }
    }

    /** @return array{daily:int, monthly:int} */
    public function usage(Wallet $wallet): array
    {
        $base = Transaction::where('source_wallet_id', $wallet->id)
            ->whereIn('status', ['successful', 'processing'])
            ->whereNotIn('type', self::EXEMPT_TYPES);

        return [
            'daily' => (int) (clone $base)->where('created_at', '>=', now()->startOfDay())->sum(\Illuminate\Support\Facades\DB::raw('amount + fee')),
            'monthly' => (int) (clone $base)->where('created_at', '>=', now()->startOfMonth())->sum(\Illuminate\Support\Facades\DB::raw('amount + fee')),
        ];
    }

    /** Résumé pour l'écran Profil (palier atteint + consommation). */
    public function summary(User $user): ?array
    {
        $l = $this->limitsFor($user);
        if (! $l || ! $user->wallet) {
            return $l;
        }
        return $l + ['usage' => $this->usage($user->wallet), 'currency' => $user->wallet->currency,
            'tiers' => $l['profile'] === 'client' ? array_map(fn ($t) => array_intersect_key($t, array_flip(['label', 'kyc', 'per_operation', 'daily', 'monthly', 'max_balance', 'international'])), config('limits.client')) : null];
    }

    protected function fmt(int $v, string $cur): string
    {
        return number_format($v, 0, ',', ' ') . ' ' . $cur;
    }
}

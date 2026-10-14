<?php

namespace App\Services\NotchPay;

use Illuminate\Support\Facades\Cache;

/** Solde du compte FlashPay chez Notch Pay (mis en cache 60 s). */
class NotchPayBalanceService
{
    public function __construct(protected NotchPayClient $client)
    {
    }

    /** { ok, accounts: [{currency, balance, pending, low}], error? } */
    public function balances(bool $refresh = false): array
    {
        if (! $this->client->enabled() || ! config('flashpay.notchpay.private_key')) {
            return ['ok' => false, 'accounts' => [], 'error' => 'Clés Notch Pay non configurées'];
        }
        if ($refresh) {
            Cache::forget('notchpay:balance');
        }
        try {
            return Cache::remember('notchpay:balance', 60, function () {
                $b = $this->client->balance();
                $low = (int) config('flashpay.notchpay.low_balance_alert', 100000);
                $accounts = [];
                foreach ($b['available'] as $cur => $amount) {
                    $accounts[] = ['currency' => strtoupper((string) $cur), 'label' => 'Notch Pay', 'balance' => (float) $amount,
                        'pending' => (float) ($b['pending'][$cur] ?? 0), 'low' => in_array(strtoupper((string) $cur), ['XAF', 'XOF'], true) && (float) $amount < $low];
                }
                return ['ok' => true, 'accounts' => $accounts, 'at' => now()->toIso8601String()];
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'accounts' => [], 'error' => $e->getMessage()];
        }
    }

    /** Solde disponible dans une devise ; null si inconnu (ne bloque pas). */
    public function availableFor(string $currency, bool $fresh = false): ?int
    {
        $b = $this->balances($fresh);
        if (! $b['ok']) {
            return null;
        }
        foreach ($b['accounts'] as $a) {
            if ($a['currency'] === strtoupper($currency)) {
                return (int) $a['balance'];
            }
        }
        return null;
    }
}

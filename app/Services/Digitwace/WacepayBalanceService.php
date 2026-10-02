<?php

namespace App\Services\Digitwace;

use App\Models\DigitwaceRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Soldes du compte FlashPay chez WacePay (par devise), montants engagés
 * (versements WacePay en cours) et disponible = solde − engagé.
 * Mis en cache 30 s ; ?refresh=1 force l'appel.
 */
class WacepayBalanceService
{
    public function __construct(protected DigitwaceClient $client)
    {
    }

    public function balances(bool $fresh = false): array
    {
        $low = (int) config('flashpay.digitwace.low_balance_alert', 100000);
        if (! $this->client->enabled()) {
            return ['ok' => false, 'configured' => false, 'error' => 'WacePay n\'est pas configuré', 'accounts' => [], 'low_balance_alert' => $low, 'checked_at' => now()->toIso8601String()];
        }
        if ($fresh) {
            Cache::forget('digitwace:balance');
        }
        try {
            $json = Cache::remember('digitwace:balance', 30, fn () => $this->client->call('get', 'balance'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'configured' => true, 'error' => $e->getMessage(), 'accounts' => [], 'low_balance_alert' => $low, 'checked_at' => now()->toIso8601String()];
        }

        $reserved = $this->reservedByCurrency();
        $accounts = [];
        foreach ($this->parse($json) as $a) {
            $cur = $a['currency'];
            $res = $reserved[$cur] ?? 0;
            $available = $a['balance'] !== null ? $a['balance'] - $res : null;
            $accounts[] = $a + [
                'reserved' => $res,
                'available' => $available,
                'low' => $available !== null && in_array($cur, ['XAF', 'XOF'], true) && $available < $low,
            ];
        }

        return [
            'ok' => true, 'configured' => true, 'accounts' => $accounts,
            'total_xaf' => collect($accounts)->whereIn('currency', ['XAF', 'XOF'])->sum('balance'),
            'low_balance_alert' => $low, 'checked_at' => now()->toIso8601String(),
        ];
    }

    /** Solde disponible pour une devise (null si inconnu). */
    public function availableFor(string $currency): ?float
    {
        $b = $this->balances();
        if (! $b['ok']) {
            return null;
        }
        $acc = collect($b['accounts'])->firstWhere('currency', strtoupper($currency));
        return $acc['available'] ?? null;
    }

    /**
     * Réponse WacePay → [{label, currency, balance}]. Formats acceptés :
     * {data:{balance, currency}} | {data:[{currency, balance}]} | {balances:{XAF: 1000}} | {balance: 1000}
     */
    public function parse(array $json): array
    {
        $data = data_get($json, 'data', $json);
        $rows = [];
        $push = function ($cur, $bal, $label = null, $extra = []) use (&$rows) {
            if (! is_numeric($bal)) {
                return;
            }
            $rows[] = ['label' => $label ?: 'Compte ' . strtoupper((string) ($cur ?: 'XAF')), 'currency' => strtoupper((string) ($cur ?: 'XAF')), 'balance' => (float) $bal] + $extra;
        };
        $bal = fn ($r) => $r['availableBalance'] ?? $r['available_balance'] ?? $r['balance'] ?? $r['solde'] ?? $r['amount'] ?? null;
        $cur = fn ($r) => $r['currency'] ?? $r['currencyCode'] ?? $r['devise'] ?? null;

        if (is_array($data) && array_is_list($data)) {
            foreach ($data as $r) {
                is_array($r) && $push($cur($r), $bal($r), $r['name'] ?? $r['label'] ?? $r['type'] ?? null);
            }
        } elseif (is_array($data) && isset($data['balances']) && is_array($data['balances'])) {
            foreach ($data['balances'] as $k => $v) {
                is_array($v) ? $push($cur($v) ?? $k, $bal($v), $v['label'] ?? null) : $push($k, $v);
            }
        } elseif (is_array($data) && $bal($data) !== null) {
            $push($cur($data), $bal($data), $data['name'] ?? null);
        } elseif (is_array($data)) {
            foreach ($data as $k => $v) { // {XAF: 1000, XOF: 200}
                if (is_string($k) && strlen($k) === 3 && is_numeric($v)) {
                    $push($k, $v);
                }
            }
        }
        return $rows;
    }

    /** Versements WacePay en cours (non finalisés) : montant par devise. */
    public function reservedByCurrency(): array
    {
        try {
            return DigitwaceRequest::where('operation', 'payout')->whereNull('finalized_at')->whereIn('status', ['new', 'pending'])
                ->with('transaction:id,amount,destination_amount,currency,destination_currency')->get()
                ->groupBy(fn ($r) => $r->transaction?->destination_currency ?: ($r->transaction?->currency ?: 'XAF'))
                ->map(fn ($g) => (float) $g->sum(fn ($r) => (int) ($r->transaction?->destination_amount ?? $r->transaction?->amount ?? 0)))
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}

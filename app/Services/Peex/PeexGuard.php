<?php

namespace App\Services\Peex;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Contrôles effectués AVANT de débiter le client sur une opération PEEX :
 *
 *   1. verifyAccount()  compte mobile money actif + nom du titulaire
 *                       (Verify Wallet / Get KYC) — payeur ET bénéficiaire ;
 *   2. checkCollect()   service de collecte PEEX activé ;
 *   3. checkPayout()    service de versement PEEX (disbursement ou remittance
 *                       selon le pays) activé ET solde suffisant pour couvrir
 *                       montant + frais PEEX + montants déjà engagés.
 *
 * Toute vérification impossible (PEEX injoignable) BLOQUE l'opération :
 * on ne débite jamais un client sans être sûr de pouvoir le servir.
 * Désactivables en test via PEEX_VERIFY_ACCOUNTS / PEEX_CHECK_BALANCE.
 */
class PeexGuard
{
    public function __construct(
        protected PeexClient $client,
        protected PeexCorridors $corridors,
    ) {
    }

    // ------------------------------------------------------ 1. Comptes

    /**
     * @param array $route résultat de PeexCorridors::resolve() (country, local, phone…)
     * @return array{ok:bool, name:?string, problem:?string}
     */
    public function verifyAccount(array $route, string $side): array
    {
        if (! config('flashpay.peex.verify_accounts', true)) {
            return ['ok' => true, 'name' => null, 'problem' => null];
        }

        $who = $side === 'source' ? 'Le numéro' : 'Le numéro du bénéficiaire';
        $key = 'peex:kyc:' . $route['phone'];

        try {
            $r = Cache::get($key);
            if ($r === null) {
                $r = $this->client->verifyWallet($route['country'], $route['local']);
                Cache::put($key, $r, $r['valid'] ? now()->addMinutes(10) : now()->addMinutes(2));
            }
        } catch (PeexException $e) {
            Log::warning('PEEX verify_wallet indisponible', ['phone' => $route['phone'], 'error' => $e->getMessage()]);
            return ['ok' => false, 'name' => null, 'problem' => "Vérification du compte {$route['phone']} impossible pour le moment. Réessayez dans quelques instants."];
        }

        if (! $r['valid']) {
            return ['ok' => false, 'name' => null, 'problem' => "{$who} {$route['phone']} n'est pas un compte mobile money actif."];
        }

        return ['ok' => true, 'name' => $r['name'] ?: null, 'problem' => null];
    }

    // ------------------------------------------------------ 2-3. Services / soldes

    /** API PEEX utilisée pour créditer un numéro de ce pays. */
    public function payoutService(string $iso): string
    {
        try {
            return ($this->corridors->country($iso)['payout_api'] ?? 'disbursement') === 'remittance' ? 'remittance' : 'disbursement';
        } catch (PeexException) {
            return 'disbursement';
        }
    }

    /** Fiche partenaire PEEX (collection/me, disbursement/me, clients/me). */
    public function account(string $service, bool $fresh = false): array
    {
        $key = "peex:me:{$service}";
        if (! $fresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $me = match ($service) {
            'collect' => $this->client->collectMe(),
            'disbursement' => $this->client->disbursementMe(),
            default => $this->client->remittanceMe(),
        };
        $me = is_array($me['data'] ?? null) ? $me['data'] : $me;
        Cache::put($key, $me, now()->addSeconds(30));

        return $me;
    }

    public function checkCollect(bool $fresh = false): ?string
    {
        if (! config('flashpay.peex.check_balance', true)) {
            return null;
        }
        try {
            $me = $this->account('collect', $fresh);
        } catch (PeexException $e) {
            Log::warning('PEEX collection/me indisponible', ['error' => $e->getMessage()]);
            return 'Paiement mobile money momentanément indisponible. Réessayez plus tard.';
        }
        if (($me['is_activated'] ?? true) === false) {
            Log::critical('PEEX : service de collecte désactivé');
            return 'Paiement mobile money momentanément indisponible (service désactivé).';
        }
        return null;
    }

    /**
     * @param int $amount montant versé au bénéficiaire (devise de destination)
     */
    public function checkPayout(string $iso, int $amount, bool $fresh = false, ?int $excludeTxId = null): ?string
    {
        if (! config('flashpay.peex.check_balance', true)) {
            return null;
        }

        $service = $this->payoutService($iso);
        try {
            $me = $this->account($service, $fresh);
        } catch (PeexException $e) {
            Log::warning("PEEX {$service}/me indisponible", ['error' => $e->getMessage()]);
            return 'Versements mobile money momentanément indisponibles. Réessayez plus tard.';
        }

        if (($me['is_activated'] ?? true) === false) {
            Log::critical("PEEX : service {$service} désactivé");
            return 'Versements mobile money momentanément indisponibles (service désactivé).';
        }

        $balance = $me[$service === 'disbursement' ? 'disbursement_solde' : 'solde'] ?? $me['solde'] ?? null;
        if (! is_numeric($balance)) {
            Log::critical("PEEX {$service}/me : solde non communiqué — contrôle impossible", ['me' => $me]);
            return 'Versements mobile money momentanément indisponibles. Réessayez plus tard.';
        }

        $feePct = max((float) ($me['orange_fees'] ?? 0), (float) ($me['mtn_fees'] ?? 0));
        $required = (int) ceil($amount * (1 + $feePct / 100));
        $reserved = $this->reserved($service, $excludeTxId);
        $available = (float) $balance - $reserved;

        if ($available < $required) {
            Log::critical("PEEX : solde {$service} insuffisant", [
                'solde' => $balance, 'engage' => $reserved, 'requis' => $required, 'pays' => $iso,
            ]);
            return 'Versements vers ce pays temporairement indisponibles. Réessayez plus tard.';
        }

        return null;
    }

    /**
     * Montants déjà promis sur un service de versement : transactions dont la
     * collecte est en cours (le versement suivra) et remboursements en attente.
     */
    public function reserved(string $service, ?int $excludeTxId = null): float
    {
        $default = config('flashpay.peex.default_country', 'CG');

        return (float) Transaction::where('status', 'processing')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('destination_rail', 'peex')->whereIn('stage', ['awaiting_source', 'awaiting_card']))
                ->orWhere('stage', 'awaiting_refund'))
            ->when($excludeTxId, fn ($q) => $q->whereKeyNot($excludeTxId))
            ->get(['id', 'amount', 'fee', 'destination_amount', 'stage', 'meta'])
            ->filter(fn (Transaction $t) => $this->payoutService(
                $t->stage === 'awaiting_refund'
                    ? ($t->meta['source_country'] ?? $default)
                    : ($t->meta['destination_country'] ?? $default)
            ) === $service)
            ->sum(fn (Transaction $t) => $t->stage === 'awaiting_refund'
                ? (int) $t->amount + (int) $t->fee
                : (int) ($t->destination_amount ?? $t->amount));
    }
}

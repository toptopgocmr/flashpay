<?php

namespace App\Support;

use App\Models\DigitwaceRequest;
use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Services\Connectors\PeexConnector;
use App\Services\Ops\PlatformSettings;
use App\Services\Peex\PeexCorridors;

/**
 * Coûts partenaires (PEEX, WacePay/Digitwace…) et revenu FlashPay par transaction.
 *
 * Un envoi mobile → mobile / carte / banque = 2 opérations partenaire :
 *   1. collecte  : débit du compte de l'expéditeur → compte principal FlashPay (suspense)
 *   2. versement : compte principal FlashPay → crédit du compte du bénéficiaire
 * Chaque opération peut coûter des frais au partenaire. On prend les frais
 * renvoyés par le partenaire (réponse / webhook) ; à défaut, on les estime avec
 * les tarifs saisis dans la console (PlatformSettings « partner_fees »).
 *
 * Marge FlashPay = frais facturés au client (+ commission marchand) − frais partenaires.
 */
class PartnerFees
{
    /** Tarifs modifiables : % du montant + fixe, par partenaire et par sens. */
    public const RATES = [
        'peex_collect' => 'PEEX — collecte (débit expéditeur)',
        'peex_payout' => 'PEEX — versement (crédit bénéficiaire)',
        'digitwace_collect' => 'WacePay — collecte mobile money',
        'digitwace_payout' => 'WacePay — versement',
        'digitwace_card' => 'WacePay — carte / compte bancaire',
        'card' => 'Carte (autre prestataire)',
    ];

    /**
     * Tarifs contractuels par défaut (offre WacePay / Digitwace du 06/10/2026 pour CIA Microfinance) :
     *  - PayIn  : 3,5 % par transaction réussie, tous pays ;
     *  - PayOut : 1 500 FCFA fixe (Cameroun, Congo, Tchad, RCA), 2,85 % (Gabon), 1,75 % (RDC).
     * Une ligne `countries` remplace le tarif général pour le pays de l'opération.
     */
    public const DEFAULTS = [
        'digitwace_collect' => ['pct' => 3.5, 'fixed' => 0, 'countries' => []],
        'digitwace_payout' => ['pct' => 0, 'fixed' => 0, 'countries' => [
            'CG' => ['pct' => 0, 'fixed' => 1500],
            'CM' => ['pct' => 0, 'fixed' => 1500],
            'TD' => ['pct' => 0, 'fixed' => 1500],
            'CF' => ['pct' => 0, 'fixed' => 1500],
            'GA' => ['pct' => 2.85, 'fixed' => 0],
            'CD' => ['pct' => 1.75, 'fixed' => 0],
        ]],
    ];

    protected const FEE_KEYS = ['fees', 'fee', 'fee_amount', 'feeamount', 'fees_amount', 'feesamount', 'transaction_fee', 'transactionfee',
        'transactionfees', 'transaction_fees', 'total_fees', 'totalfees', 'totalfee', 'commission', 'charges', 'charge', 'frais', 'partner_fee'];

    public static function rates(): array
    {
        $saved = (array) app(PlatformSettings::class)->get('partner_fees', []);
        $out = [];
        foreach (self::RATES as $k => $label) {
            $r = $saved[$k] ?? self::DEFAULTS[$k] ?? [];
            $countries = [];
            foreach ((array) ($r['countries'] ?? []) as $iso => $c) {
                $iso = strtoupper((string) $iso);
                if (preg_match('/^[A-Z]{2}$/', $iso)) {
                    $countries[$iso] = ['pct' => (float) ($c['pct'] ?? 0), 'fixed' => (int) ($c['fixed'] ?? 0)];
                }
            }
            ksort($countries);
            $out[$k] = [
                'label' => $label,
                'pct' => (float) ($r['pct'] ?? 0),
                'fixed' => (int) ($r['fixed'] ?? 0),
                'countries' => (object) $countries,
            ];
        }
        return $out;
    }

    /** Normalise les tarifs envoyés par la console (pour l'enregistrement). */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::RATES as $k => $label) {
            $r = (array) ($input[$k] ?? []);
            $countries = [];
            foreach ((array) ($r['countries'] ?? []) as $iso => $c) {
                $iso = strtoupper(trim((string) ($c['country'] ?? $iso)));
                if (preg_match('/^[A-Z]{2}$/', $iso)) {
                    $countries[$iso] = ['pct' => min(50, max(0, (float) ($c['pct'] ?? 0))), 'fixed' => min(1000000, max(0, (int) ($c['fixed'] ?? 0)))];
                }
            }
            $out[$k] = [
                'pct' => min(50, max(0, (float) ($r['pct'] ?? 0))),
                'fixed' => min(1000000, max(0, (int) ($r['fixed'] ?? 0))),
                'countries' => $countries,
            ];
        }
        return $out;
    }

    /** Pays d'un côté de l'opération (in = expéditeur, out = bénéficiaire), sans appel réseau. */
    protected static function countryOf(Transaction $tx, string $side): ?string
    {
        $m = $tx->meta ?? [];
        $hint = $m[$side === 'in' ? 'source_country' : 'destination_country'] ?? null;
        $acc = $side === 'in' ? $tx->source_account : $tx->destination_account;
        if ($acc) {
            try {
                return strtoupper((string) (app(PeexCorridors::class)->resolve((string) $acc, $hint)['country'] ?? $hint)) ?: null;
            } catch (\Throwable) {
                // numéro non reconnu : on garde l'indication de pays
            }
        }
        return $hint ? strtoupper((string) $hint) : null;
    }

    /**
     * @return array{currency:string, billed:int, client_fee:int, merchant_fee:int, partner_total:int, margin:int,
     *               complete:bool, legs:array<int,array>}
     */
    public static function of(Transaction $tx, ?array $rates = null, bool $withFlow = false): array
    {
        $rates ??= self::rates();
        $legs = [];
        $peex = $tx->relationLoaded('peexRequests') ? $tx->peexRequests : PeexRequest::where('transaction_id', $tx->id)->get();
        $wace = $tx->relationLoaded('digitwaceRequests') ? $tx->digitwaceRequests : DigitwaceRequest::where('transaction_id', $tx->id)->get();
        $meta = $tx->meta ?? [];
        $hosted = ($meta['card_driver'] ?? null) === 'wacepay';

        // 1. Collecte (débit du compte de l'expéditeur)
        $srcRail = $tx->source_rail ?: 'wallet';
        if ($srcRail !== 'wallet' && $srcRail !== 'cash') {
            if ($srcRail === 'peex') {
                $req = $peex->first(fn ($r) => PeexConnector::legOf((string) $r->track_id) === 'C');
                $legs[] = self::leg('collect', 'PEEX', 'peex_collect', $req?->track_id ?? $tx->source_external_ref, $req?->status,
                    (int) $tx->amount + (int) $tx->fee, $tx->currency, self::reported($req, 'peex'), $rates, self::countryOf($tx, 'in'));
            } elseif ($srcRail === 'digitwace' || (in_array($srcRail, ['card', 'bank'], true) && $hosted)) {
                $req = $wace->first(fn ($r) => in_array($r->operation, ['payin', 'checkout'], true));
                $key = $srcRail === 'digitwace' ? 'digitwace_collect' : 'digitwace_card';
                $legs[] = self::leg('collect', 'WacePay', $key, $req?->wace_id ?? $tx->source_external_ref, $req?->status,
                    (int) $tx->amount + (int) $tx->fee, $tx->currency, self::reported($req, 'wace'), $rates, self::countryOf($tx, 'in'));
            } elseif ($srcRail === 'card') {
                $legs[] = self::leg('collect', 'Carte', 'card', $tx->source_external_ref, null,
                    (int) $tx->amount + (int) $tx->fee, $tx->currency, null, $rates);
            }
        }

        // 2. Versement (crédit du compte du bénéficiaire)
        $outCur = $tx->destination_currency ?: $tx->currency;
        $out = (int) ($tx->destination_amount ?? $tx->amount);
        if ($tx->destination_rail === 'peex') {
            $req = $peex->first(fn ($r) => PeexConnector::legOf((string) $r->track_id) === 'D');
            $legs[] = self::leg('payout', 'PEEX', 'peex_payout', $req?->track_id ?? $tx->destination_external_ref, $req?->status,
                $out, $outCur, self::reported($req, 'peex'), $rates, self::countryOf($tx, 'out'));
        } elseif ($tx->destination_rail === 'digitwace') {
            $req = $wace->first(fn ($r) => $r->operation === 'payout');
            $legs[] = self::leg('payout', 'WacePay', 'digitwace_payout', $req?->wace_id ?? $tx->destination_external_ref, $req?->status,
                $out, $outCur, self::reported($req, 'wace'), $rates, self::countryOf($tx, 'out'));
        }

        // 3. Remboursements PEEX (automatiques ou manuels) : coût supplémentaire
        foreach ($peex->filter(fn ($r) => in_array(PeexConnector::legOf((string) $r->track_id), ['R', 'M'], true)) as $req) {
            $legs[] = self::leg('refund', 'PEEX', 'peex_payout', $req->track_id, $req->status,
                (int) round((float) $req->amount), $req->currency ?: $tx->currency, self::reported($req, 'peex'), $rates);
        }

        // Conversion des frais en devise de la transaction (versement en devise cible)
        $ratio = ($tx->destination_amount && $outCur !== $tx->currency) ? ((int) $tx->amount / max(1, (int) $tx->destination_amount)) : 1;
        $partner = 0;
        foreach ($legs as &$l) {
            if ($l['fee'] === null || $l['status'] === 'failed') {
                $l['fee_tx'] = 0; // opération refusée : rien de facturé par le partenaire
                continue;
            }
            $l['fee_tx'] = $l['currency'] === $tx->currency ? $l['fee'] : (int) round($l['fee'] * $ratio);
            $partner += $l['fee_tx'];
        }
        unset($l);

        $ok = $tx->status === 'successful';
        $clientFee = $ok ? (int) $tx->fee : 0;   // frais remboursés si la transaction échoue
        $merchantFee = $ok ? (int) $tx->merchant_fee : 0;
        $billed = $clientFee + $merchantFee;

        return [
            'currency' => $tx->currency,
            'client_fee' => $clientFee,
            'merchant_fee' => $merchantFee,
            'billed' => $billed,
            'partner_total' => $partner,
            'margin' => $billed - $partner,
            'complete' => collect($legs)->every(fn ($l) => $l['fee_source'] !== null || $l['status'] === 'failed'),
            'flow' => $withFlow ? self::flow($tx, $legs) : null,
            'legs' => $legs,
        ];
    }

    protected static function leg(string $kind, string $partner, string $rateKey, ?string $ref, ?string $status, int $amount, string $currency, ?float $reported, array $rates, ?string $country = null): array
    {
        $source = null;
        $fee = null;
        $r = $rates[$rateKey] ?? null;
        $byCountry = $r && $country ? (((array) ($r['countries'] ?? []))[$country] ?? null) : null;
        if ($byCountry) {
            $r = ['pct' => (float) $byCountry['pct'], 'fixed' => (int) $byCountry['fixed']];
        }
        if ($reported !== null) {
            $fee = (int) round($reported);
            $source = 'partner';
        } elseif ($r && ($r['pct'] > 0 || $r['fixed'] > 0)) {
            $fee = (int) round($amount * $r['pct'] / 100) + $r['fixed'];
            $source = 'estimate';
        }
        return [
            'kind' => $kind,
            'label' => ['collect' => 'Collecte (débit expéditeur)', 'payout' => 'Versement (crédit bénéficiaire)', 'refund' => 'Remboursement'][$kind],
            'partner' => $partner,
            'reference' => $ref,
            'status' => self::norm($status),
            'amount' => $amount,
            'currency' => $currency,
            'fee' => $fee,
            'country' => $country,
            'rate' => $r ? ['pct' => (float) $r['pct'], 'fixed' => (int) $r['fixed'], 'by_country' => (bool) $byCountry] : null,
            'fee_source' => $source, // partner = renvoyé par le partenaire ; estimate = tarif console ; null = inconnu
        ];
    }

    protected static function norm(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        $s = strtolower($s);
        return match (true) {
            in_array($s, ['successful', 'success', 'paid', 'completed', 'complete', 'succeeded', 'approved'], true) => 'successful',
            in_array($s, ['failed', 'rejected', 'canceled', 'cancelled', 'error', 'declined', 'expired'], true) => 'failed',
            default => 'pending',
        };
    }

    /** Frais renvoyés par le partenaire (réponse, statut ou webhook). */
    protected static function reported($req, string $type): ?float
    {
        if (! $req) {
            return null;
        }
        if ($type === 'peex' && is_numeric($req->fees ?? null) && (float) $req->fees > 0) {
            return (float) $req->fees;
        }
        $payloads = $type === 'peex'
            ? [(array) ($req->last_callback ?? []), (array) ($req->response_payload ?? [])]
            : [(array) ($req->last_callback ?? []), (array) ($req->last_response ?? [])];
        foreach ($payloads as $p) {
            if (($f = self::findFee($p)) !== null) {
                return $f;
            }
        }
        return null;
    }

    public static function findFee(array $a, int $depth = 0): ?float
    {
        if ($depth > 4) {
            return null;
        }
        foreach ($a as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::FEE_KEYS, true) && is_numeric($v)) {
                return (float) $v;
            }
        }
        // Montant net − montant envoyé (ex. WacePay : amount 100, netAmount 102 → 2 de frais)
        $lower = array_change_key_case(array_filter($a, 'is_string', ARRAY_FILTER_USE_KEY));
        $net = $lower['netamount'] ?? $lower['net_amount'] ?? null;
        $amt = $lower['amount'] ?? null;
        if (is_numeric($net) && is_numeric($amt) && (float) $net !== (float) $amt) {
            return abs((float) $net - (float) $amt);
        }
        foreach ($a as $v) {
            if (is_array($v) && ($f = self::findFee($v, $depth + 1)) !== null) {
                return $f;
            }
        }
        return null;
    }

    /** Parcours des fonds : expéditeur → compte principal FlashPay → bénéficiaire. */
    protected static function flow(Transaction $tx, array $legs): array
    {
        $g = TransactionPresenter::gateway($tx);
        $st = fn ($k) => collect($legs)->firstWhere('kind', $k)['status'] ?? null;
        $steps = [];
        $steps[] = ['step' => 'debit', 'label' => 'Débit du compte de l\'expéditeur', 'account' => $g['in']['operator'] . ($g['in']['account'] ? ' · ' . $g['in']['account'] : ''),
            'partner' => $g['in']['partner'] ?? 'FlashPay', 'status' => $tx->source_rail === 'wallet' ? ($tx->status === 'failed' && ! $tx->ledgerEntries()->exists() ? 'failed' : 'successful') : $st('collect')];
        $steps[] = ['step' => 'main', 'label' => 'Compte principal FlashPay', 'account' => 'Trésorerie FlashPay (flashpay:suspense)', 'partner' => 'FlashPay',
            'status' => in_array($tx->stage, ['awaiting_source', 'awaiting_card'], true) ? 'pending' : ($tx->ledgerEntries()->where('account', 'flashpay:suspense')->where('type', 'credit')->exists() ? 'successful' : ($tx->status === 'failed' ? 'failed' : 'pending'))];
        $steps[] = ['step' => 'credit', 'label' => 'Crédit du compte du bénéficiaire', 'account' => $g['out']['operator'] . ($g['out']['account'] ? ' · ' . $g['out']['account'] : ''),
            'partner' => $g['out']['partner'] ?? 'FlashPay', 'status' => $tx->destination_rail === 'wallet' ? ($tx->status === 'successful' ? 'successful' : ($tx->status === 'failed' || $tx->status === 'reversed' ? 'failed' : 'pending')) : $st('payout')];
        return $steps;
    }
}

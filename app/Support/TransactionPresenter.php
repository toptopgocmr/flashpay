<?php

namespace App\Support;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

/**
 * Présentation « journal » d'une transaction, du point de vue d'un utilisateur
 * (ou du client concerné quand c'est la console qui regarde) :
 *
 *   Recharges (cash_in, dépôt)  → « Recharge compte FlashPay », CRÉDIT ↓
 *                                 canal Wallet → Wallet | Mobile → Wallet |
 *                                 Carte bancaire → Wallet | Cash → Wallet
 *   Réceptions d'argent         → « Transfert entrant » / « Paiement entrant », CRÉDIT ↓
 *   Retraits                    → « Retrait », DÉBIT ↑
 *   Envois                      → « Transfert sortant » / « Paiement sortant », DÉBIT ↑
 *
 * Chaque ligne porte le nom de la contrepartie : l'expéditeur pour un crédit,
 * le bénéficiaire pour un débit.
 */
class TransactionPresenter
{
    public const RECHARGE_TYPES = ['cash_in', 'deposit', 'float_topup'];
    public const WITHDRAWAL_TYPES = ['withdrawal', 'cash_out', 'cash_pickup', 'atm_withdrawal'];
    public const PAYMENT_TYPES = ['merchant_payment', 'qr_payment', 'nfc_payment', 'manual_payment', 'collection',
        'ecommerce_payment', 'mini_program_payment', 'split_payment'];
    public const REFUND_TYPES = ['refund', 'gift_refund'];

    /**
     * @param int[]|null $viewerWalletIds wallets de l'utilisateur qui regarde ; null = vue console
     *                                    (sens donné du point de vue du client concerné).
     */
    public static function present(Transaction $tx, ?array $viewerWalletIds = null, ?int $viewerId = null): array
    {
        $type = (string) $tx->type;
        $credit = self::isCredit($tx, $viewerWalletIds, $viewerId);
        $recharge = in_array($type, self::RECHARGE_TYPES, true)
            && ($viewerWalletIds === null || in_array((int) $tx->destination_wallet_id, $viewerWalletIds, true));

        if ($recharge) {
            $credit = true;
        }

        $label = match (true) {
            $recharge => 'Recharge compte FlashPay',
            $credit && in_array($type, self::REFUND_TYPES, true) => 'Remboursement (entrant)',
            $credit && in_array($type, self::PAYMENT_TYPES, true) => 'Paiement entrant',
            $credit => 'Transfert entrant',
            in_array($type, self::WITHDRAWAL_TYPES, true) => 'Retrait',
            in_array($type, self::PAYMENT_TYPES, true) => 'Paiement sortant',
            in_array($type, self::RECHARGE_TYPES, true) => 'Recharge client', // vue agent : il a rechargé un client
            default => 'Transfert sortant',
        };

        $detail = match ($type) {
            'gift' => 'Cadeau / cagnotte',
            'gift_claim' => 'Cadeau reçu',
            'gift_refund' => 'Cadeau non réclamé',
            'refund' => ! empty($tx->meta['refund_of']) ? 'Remboursement de ' . $tx->meta['refund_of'] . (! empty($tx->meta['refunded_via']) ? ' via ' . $tx->meta['refunded_via'] : '') : null,
            'split_payment' => 'Contribution à une cagnotte',
            'bank_transfer' => 'Virement bancaire',
            'cash_pickup' => 'Retrait avec code',
            'atm_withdrawal' => 'Retrait au GAB',
            'cash_out' => 'Retrait chez un agent',
            default => in_array($type, self::PAYMENT_TYPES, true) ? ($tx->meta['merchant_name'] ?? null) : null,
        };
        if (($tx->meta['channel'] ?? null) === 'money_request') {
            $detail = 'Demande d\'argent';
        }

        [$name, $phone] = $credit ? self::sender($tx) : self::beneficiary($tx);

        return [
            'direction' => $credit ? 'credit' : 'debit',        // crédit / débit du wallet
            'flow' => $credit ? 'in' : 'out',                   // entrant / sortant (flèche ↓ / ↑)
            'arrow' => $credit ? '↓' : '↑',
            'direction_label' => $credit ? 'Crédit' : 'Débit',
            'label' => $label,
            'detail' => $detail,
            'channel' => self::channel($tx, $recharge),          // ex. « Mobile → Wallet »
            'counterparty_role' => $credit ? 'Expéditeur' : 'Bénéficiaire',
            'counterparty_name' => $name,
            'counterparty_phone' => $phone,
            // Remboursée par une opération « Remboursement » distincte : le débit reste visible ici,
            // le retour de l'argent apparaît sur la ligne du remboursement.
            'signed_amount' => ($tx->status === 'failed' || ($tx->status === 'reversed' && empty($tx->meta['refund_transaction']))) ? 0 : ($credit ? 1 : -1) * ((int) $tx->amount + ($credit ? 0 : (int) $tx->fee)),
            'fee_charged' => ($tx->status === 'failed' || ($tx->status === 'reversed' && empty($tx->meta['refund_transaction']))) ? 0 : (int) $tx->fee,
            'refund_transaction' => $tx->meta['refund_transaction'] ?? null,
            'refund_of' => $tx->meta['refund_of'] ?? null,
            'partner' => self::partner($tx),                    // partenaire qui a géré les flux (PEEX, WacePay…)
        ] + self::parties($tx) + ['details' => self::details($tx, $credit, $label)];
    }

    /** Expéditeur ET bénéficiaire, toujours renseignés quand c'est possible. */
    public static function parties(Transaction $tx): array
    {
        [$sn, $sp] = self::sender($tx);
        [$bn, $bp] = self::beneficiary($tx);

        return [
            'sender_name' => $sn ?: ($sp ? null : 'FlashPay'),
            'sender_phone' => $sp ? self::phone($sp) : null,
            'beneficiary_name' => $bn,
            'beneficiary_phone' => $bp ? self::phone($bp) : null,
        ];
    }

    /**
     * Récapitulatif détaillé (libellé → valeur) : affiché dans l'app (reçu de fin
     * d'opération, détail d'une transaction, détail d'une notification).
     *
     * @return array<int, array{label:string, value:string}>
     */
    public static function details(Transaction $tx, ?bool $credit = null, ?string $label = null): array
    {
        $m = $tx->meta ?? [];
        $cur = $tx->currency ?: 'XAF';
        $money = fn ($v, $c = null) => number_format((int) $v, 0, ',', ' ') . ' ' . ($c ?: $cur);
        $p = self::parties($tx);
        $who = fn ($name, $phone) => trim(($name ?: '') . ($phone ? ($name ? ' · ' : '') . $phone : '')) ?: null;
        $received = (int) ($tx->destination_amount ?? $tx->amount) - (int) $tx->merchant_fee;
        $operator = fn ($op, $country) => trim(implode(' · ', array_filter([$op ? ucfirst(strtolower((string) $op)) : null, $country])));

        $rows = [
            ['Opération', $label ?? $tx->typeLabel()],
            ['Type', self::channel($tx)],
            ['Statut', $tx->statusLabel()],
            ['Expéditeur', $who($p['sender_name'], $p['sender_phone'])],
            ['Compte débité', $tx->source_rail === 'wallet' ? 'Wallet FlashPay' : ($tx->source_rail === 'card' ? ($tx->source_account ?: 'Carte bancaire' . (! empty($m['card_last4']) ? ' •••• ' . $m['card_last4'] : '')) : ($tx->source_rail === 'bank' ? ($tx->source_account ?: 'Compte bancaire') : ($tx->source_account ? self::phone($tx->source_account) . ($operator($m['source_operator'] ?? null, $m['source_country'] ?? null) ? ' (' . $operator($m['source_operator'] ?? null, $m['source_country'] ?? null) . ')' : '') : null)))],
            ['Bénéficiaire', $who($p['beneficiary_name'], $p['beneficiary_phone'])],
            ['Compte crédité', $tx->destination_rail === 'wallet' ? 'Wallet FlashPay' : match ($tx->destination_rail) {
                'cash' => 'Retrait en espèces', 'atm' => 'Retrait au GAB', 'bank' => trim('Compte bancaire ' . ($m['bank_name'] ?? '')),
                default => $tx->destination_account ? self::phone($tx->destination_account) . ($operator($m['destination_operator'] ?? null, $m['destination_country'] ?? null) ? ' (' . $operator($m['destination_operator'] ?? null, $m['destination_country'] ?? null) . ')' : '') : null,
            }],
            ['Marchand', $m['merchant_name'] ?? null],
            ['Agent', $m['agent_name'] ?? null],
            ['Montant', $money($tx->amount)],
            // Opération échouée ou remboursée : aucun frais n'est conservé
            ['Frais', match (true) {
                $tx->status === 'failed' => $money(0) . ' (non prélevés)',
                $tx->status === 'reversed' => $money(0) . ($tx->fee > 0 ? ' (' . $money($tx->fee) . ' remboursés)' : ''),
                default => $money($tx->fee),
            }],
            ['Total débité', match ($tx->status) {
                'failed' => $money(0),
                'reversed' => $money(0) . ' (remboursé)',
                default => $money((int) $tx->amount + (int) $tx->fee),
            }],
            ['Montant reçu', $money($received, $tx->destination_currency ?: $cur)],
            ['Taux de change', ! empty($m['fx']['rate']) ? '1 ' . $cur . ' = ' . $m['fx']['rate'] . ' ' . ($tx->destination_currency ?? '') : null],
            ['Motif', $m['note'] ?? $m['purpose_label'] ?? $m['description'] ?? null],
            ['Raison', in_array($tx->status, ['failed', 'reversed'], true) ? $tx->failure_reason : null],
            ['Remboursement', self::refundLine($tx, $money)],
            ['Référence', $tx->reference],
            ['Réf. opérateur', $tx->destination_external_ref ?: $tx->source_external_ref],
            ['Date', $tx->created_at?->timezone(config('app.display_timezone', 'Africa/Brazzaville'))->format('d/m/Y à H:i')],
            ['Finalisée le', $tx->completed_at?->timezone(config('app.display_timezone', 'Africa/Brazzaville'))->format('d/m/Y à H:i')],
        ];

        // Vue du bénéficiaire (crédit) : les frais et le total débité concernent l'expéditeur
        // (sauf recharge de son propre compte : c'est lui qui paie les frais)
        $hidden = $credit === true && ! in_array($tx->type, self::RECHARGE_TYPES, true) ? ['Frais', 'Total débité', 'Compte débité'] : [];

        return array_values(array_map(
            fn ($r) => ['label' => $r[0], 'value' => (string) $r[1]],
            array_filter($rows, fn ($r) => $r[1] !== null && $r[1] !== '' && ! in_array($r[0], $hidden, true)),
        ));
    }

    /**
     * Ce qui est arrivé à l'argent d'une opération échouée : rien n'a été prélevé, remboursé (où, quand),
     * remboursement en cours ou remboursement manuel par le support. Null pour une opération réussie.
     */
    public static function refundLine(Transaction $tx, callable $money): ?string
    {
        $m = $tx->meta ?? [];
        $r = (array) ($m['outcome']['refund'] ?? []);
        $total = (int) $tx->amount + (int) $tx->fee;
        $tz = config('app.display_timezone', 'Africa/Brazzaville');
        $when = function () use ($r, $tx, $tz) {
            $at = ! empty($r['at']) ? \Illuminate\Support\Carbon::parse($r['at']) : $tx->updated_at;
            return $at ? ' le ' . $at->timezone($tz)->format('d/m/Y à H:i') : '';
        };
        $place = function ($to) use ($tx, $m) {
            if ($to === 'wallet' || str_starts_with((string) ($m['refunded_to'] ?? ''), 'wallet:')) {
                return 'votre wallet FlashPay';
            }
            return $to ? self::phone((string) $to) : ($tx->source_rail === 'wallet' ? 'votre wallet FlashPay' : self::phone((string) $tx->source_account));
        };

        return match (true) {
            $tx->status === 'reversed' => 'Remboursé : ' . $money($total) . ' (montant + frais) sur ' . $place($r['to'] ?? null) . $when(),
            $tx->stage === 'awaiting_refund' => 'Remboursement en cours : ' . $money($total) . ' vers ' . $place($tx->source_account),
            $tx->status === 'failed' && ($r['to'] ?? null) === 'manual' => 'Débit effectué : ' . $money($total) . ' remboursés par le support FlashPay (aucune action de votre part)',
            $tx->status === 'failed' && empty($m['outcome']['debit']) => 'Aucun montant prélevé : rien à rembourser',
            $tx->status === 'failed' => 'Remboursement en cours de traitement par le support FlashPay',
            default => null,
        };
    }

    /**
     * Débit / crédit / remboursement en clair quand le crédit a échoué après le débit
     * (null si l'opération n'est pas concernée).
     * @return ?array{debit:string, credit:string, refund:?string, attempts:array, refund_done:bool}
     */
    public static function outcome(Transaction $tx): ?array
    {
        $o = $tx->meta['outcome'] ?? null;
        if (! $o || empty($o['credit'])) {
            return null;
        }
        $d = (array) ($o['debit'] ?? []);
        $c = (array) $o['credit'];
        $r = (array) ($o['refund'] ?? []);
        $n = fn ($v) => number_format((int) $v, 0, ',', ' ');
        $from = ($d['account'] ?? null) === 'wallet' ? 'wallet FlashPay' : (string) ($d['account'] ?? $tx->source_account);
        return [
            'debit' => "Débit effectué : {$n($d['amount'] ?? ($tx->amount + $tx->fee))} {$tx->currency} prélevés sur {$from}" . (! empty($d['partner']) && $d['partner'] !== 'FlashPay' ? " ({$d['partner']})" : ''),
            'credit' => 'Crédit en échec vers ' . ($c['account'] ?? $tx->destination_account ?? 'le bénéficiaire') . (! empty($c['partner']) ? " ({$c['partner']})" : '') . ' : ' . ($c['error'] ?? 'erreur du partenaire'),
            'refund' => match (true) {
                ! $r => $tx->stage === 'awaiting_refund' ? 'Remboursement en cours sur ' . $tx->source_account : null,
                ($r['to'] ?? null) === 'manual' => 'Remboursement automatique impossible : remboursement manuel par le support',
                ($r['to'] ?? null) === 'wallet' => 'Remboursé (montant + frais) sur le wallet FlashPay' . (! empty($r['why']) ? ' — ' . $r['why'] : ''),
                default => 'Remboursé sur ' . ($r['to'] ?? $tx->source_account),
            },
            'refund_done' => (bool) ($r['done'] ?? false),
            'attempts' => array_values(array_map(fn ($a) => ($a['partner'] ?? $a['rail']) . ' : ' . ($a['error'] ?? ''), (array) ($tx->meta['payout_attempts'] ?? []))),
        ];
    }

    /** « PEEX », « WacePay (Digitwace) », « PEEX → WacePay (Digitwace) » ; null si 100 % interne. */
    public static function partner(Transaction $tx): ?string
    {
        $map = ['peex' => 'peex', 'digitwace' => 'digitwace', 'wacepay_remit' => 'wacepay_remit', 'terrapay' => 'terrapay', 'pawapay' => 'pawapay', 'pawapay_momo' => 'pawapay'];
        $in = $map[$tx->source_rail] ?? null;
        $out = $map[$tx->destination_rail] ?? null;
        $names = array_values(array_unique(array_filter([
            \App\Services\Peex\PeexCorridors::partnerName($in),
            \App\Services\Peex\PeexCorridors::partnerName($out),
        ])));
        if (in_array($tx->source_rail, ['card', 'bank'], true)) {
            $viaWace = ($tx->meta['card_driver'] ?? null) === 'wacepay';
            $label = $tx->source_rail === 'bank' ? 'Prélèvement bancaire' : 'Carte (3-D Secure)';
            array_unshift($names, $viaWace ? "WacePay ({$label})" : $label);
        }
        return $names ? implode(' → ', $names) : null;
    }

    /**
     * Opérateurs et passerelle qui ont traité l'opération (console) :
     * entrée (compte débité) et sortie (compte crédité), partenaire, track_id.
     */
    public static function gateway(Transaction $tx): array
    {
        $corr = app(\App\Services\Peex\PeexCorridors::class);
        $leg = function (?string $rail, ?string $account, ?string $hint, bool $leg_in = false) use ($corr, $tx) {
            $rail = $rail ?: 'wallet';
            $out = ['rail' => $rail, 'partner' => null, 'operator' => null, 'account' => $account];
            $hosted = $leg_in && in_array($rail, ['card', 'bank'], true) && ($tx->meta['card_driver'] ?? null) === 'wacepay';
            switch ($rail) {
                case 'wacepay_remit':
                case 'terrapay':
                case 'pawapay':
                    $out['partner'] = \App\Services\Peex\PeexCorridors::partnerName($rail);
                    $m = $tx->meta['remit'] ?? [];
                    $out['country'] = $m['country'] ?? null;
                    $out['operator'] = match ($m['method'] ?? 'wallet') {
                        'bank' => 'Compte bancaire' . (! empty($m['account']['bank_name']) ? ' · ' . $m['account']['bank_name'] : ''),
                        'cash' => 'Retrait cash' . (! empty($m['account']['city']) ? ' · ' . $m['account']['city'] : ''),
                        default => 'Mobile money' . (! empty($m['service']['name']) ? ' · ' . $m['service']['name'] : ''),
                    } . ' (' . ($m['country'] ?? '?') . ')';
                    break;
                case 'peex':
                case 'digitwace':
                case 'pawapay_momo':
                    $out['partner'] = \App\Services\Peex\PeexCorridors::partnerName($rail === 'pawapay_momo' ? 'pawapay' : $rail);
                    if ($account) {
                        try {
                            $r = $corr->resolve($account, $hint, false);
                            $out['operator'] = $r['operator'] ?: 'Mobile money ' . ($r['country'] ?? '');
                            $out['country'] = $r['country'] ?? null;
                        } catch (\Throwable) {
                            $out['operator'] = 'Mobile money';
                        }
                    }
                    break;
                case 'card':
                    $out['operator'] = $account ?: 'Carte Visa / Mastercard';
                    $out['partner'] = $hosted ? 'WacePay' : 'Carte (3-D Secure)';
                    $out['rail'] = $hosted ? 'digitwace' : 'card';
                    break;
                case 'bank':
                    $out['operator'] = $leg_in ? ($account ?: 'Compte bancaire (prélèvement)') : 'Virement bancaire';
                    $out['partner'] = $hosted ? 'WacePay' : null;
                    $out['rail'] = $hosted ? 'digitwace' : 'bank';
                    break;
                case 'cash':
                    $out['operator'] = 'Espèces (agent)';
                    break;
                default:
                    $out['operator'] = 'Wallet FlashPay';
            }
            return $out;
        };
        $meta = $tx->meta ?? [];
        $tracks = $tx->relationLoaded('peexRequests')
            ? $tx->peexRequests->pluck('track_id')->all()
            : [];

        return [
            'in' => $leg($tx->source_rail, $tx->source_account, $meta['source_country'] ?? null, true),
            'out' => $leg($tx->destination_rail, $tx->destination_account, $meta['destination_country'] ?? null),
            'partner' => self::partner($tx),
            'track_ids' => $tracks,
        ];
    }

    public static function phone(?string $p): ?string
    {
        if (! $p) {
            return null;
        }
        $d = preg_replace('/\D/', '', $p);
        return $d ? '+' . $d : $p;
    }

    /** Wallets d'un utilisateur (à calculer une fois par requête). */
    public static function walletIdsOf(User $user): array
    {
        return Wallet::where('user_id', $user->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    protected static function isCredit(Transaction $tx, ?array $wallets, ?int $viewerId): bool
    {
        $src = (int) $tx->source_wallet_id;
        $dst = (int) $tx->destination_wallet_id;

        if ($wallets === null) {
            // Vue console : un wallet client est crédité sans que l'argent vienne d'un wallet client
            if (in_array($tx->type, self::RECHARGE_TYPES, true) || in_array($tx->type, self::REFUND_TYPES, true) || $tx->type === 'gift_claim') {
                return true;
            }
            return $dst && ! $src;
        }

        $mineIn = $dst && in_array($dst, $wallets, true);
        $mineOut = $src && in_array($src, $wallets, true);
        if ($mineIn && ! $mineOut) {
            return true;
        }
        if ($mineOut) {
            return false;
        }
        // Remboursement automatique sur le numéro mobile money débité : crédit pour le client concerné
        if (in_array($tx->type, self::REFUND_TYPES, true) && $viewerId && (int) $tx->initiated_by === (int) $viewerId) {
            return true;
        }
        // Ni source ni destination sur un wallet de l'utilisateur (ex. envoi mobile → mobile qu'il a initié)
        return false;
    }

    /** Canal « source → destination » lisible. */
    public static function channel(Transaction $tx, bool $recharge = false): string
    {
        $meta = $tx->meta ?? [];
        $src = match (true) {
            ($meta['channel'] ?? null) === 'agent' && $tx->type === 'cash_in' => 'Cash',
            $tx->source_rail === 'card' => 'Carte bancaire',
            $tx->source_rail === 'wallet', $tx->source_rail === 'treasury' => 'Wallet',
            $tx->source_rail === 'cash' => 'Cash',
            in_array($tx->source_rail, ['peex', 'digitwace', 'pawapay_momo', 'mobile'], true) => 'Mobile',
            default => ucfirst((string) $tx->source_rail),
        };
        if ($tx->source_rail === 'treasury') {
            $src = 'FlashPay';
        }
        $dst = match ($tx->destination_rail) {
            'wallet' => 'Wallet',
            'cash', 'atm' => 'Cash',
            'bank' => 'Banque',
            'card' => 'Carte bancaire',
            'peex', 'digitwace', 'pawapay_momo', 'mobile' => 'Mobile',
            'wacepay_remit', 'terrapay', 'pawapay' => 'International',
            default => ucfirst((string) $tx->destination_rail),
        };
        if (in_array($tx->type, self::PAYMENT_TYPES, true) && ! empty($meta['merchant_id'])) {
            $dst = 'Marchand';
        }

        return "{$src} → {$dst}";
    }

    /** Expéditeur : titulaire vérifié chez l'opérateur, agent, payeur, propriétaire du wallet source… */
    protected static function sender(Transaction $tx): array
    {
        $m = $tx->meta ?? [];
        $ownerSrc = $tx->source_wallet_id ? $tx->sourceWallet?->user : null;
        $name = match (true) {
            ($m['channel'] ?? null) === 'agent' && ! empty($m['agent_name']) => 'Agent ' . $m['agent_name'],
            ! empty($m['payer_verified_name']) => $m['payer_verified_name'],
            ! empty($m['sender_name']) => $m['sender_name'],
            ! empty($m['payer_name']) => $m['payer_name'],
            (bool) $ownerSrc => $ownerSrc->full_name,
            in_array($tx->type, self::REFUND_TYPES, true) => 'FlashPay',
            default => $tx->initiator?->full_name,
        };
        $phone = $m['sender_phone'] ?? $tx->source_account ?? $ownerSrc?->phone;

        return [$name ?: null, $phone ? (string) $phone : null];
    }

    protected static function beneficiary(Transaction $tx): array
    {
        $m = $tx->meta ?? [];
        $ownerDst = $tx->destination_wallet_id ? $tx->destinationWallet?->user : null;
        $name = $m['beneficiary_verified_name'] ?? $m['beneficiary_name'] ?? $m['merchant_name'] ?? $ownerDst?->full_name
            ?? (($m['channel'] ?? null) === 'gift' ? 'Destinataires du cadeau' : null);
        $phone = $m['beneficiary_phone'] ?? $tx->destination_account ?? $ownerDst?->phone;

        return [$name ?: null, $phone ? (string) $phone : null];
    }
}

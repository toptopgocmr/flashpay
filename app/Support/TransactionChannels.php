<?php

namespace App\Support;

/**
 * Classement des transactions par canal (une transaction = un seul canal),
 * regroupés en familles pour le tableau de bord :
 *   Retraits  : QR code / bon de retrait, wallet, virement bancaire
 *   Recharges : cash chez un agent, carte prépayée / bancaire, mobile money → wallet
 *   Paiements : wallet, mobile money, banque / carte
 *   Transferts & autres : wallet → wallet, wallet → mobile money, interop, float agent…
 */
class TransactionChannels
{
    public const FAMILIES = [
        'withdrawals' => ['label' => 'Retraits', 'channels' => ['withdrawal_qr', 'withdrawal_wallet', 'bank_transfer']],
        'deposits' => ['label' => 'Recharges', 'channels' => ['deposit_agent', 'deposit_card', 'deposit_mm']],
        'payments' => ['label' => 'Paiements', 'channels' => ['payment_wallet', 'payment_mm', 'payment_bank']],
        'transfers' => ['label' => 'Transferts & autres', 'channels' => ['transfer_wallet', 'transfer_to_mm', 'interop', 'card_transfer', 'agent_float', 'refund', 'adjustment']],
    ];

    public const LABELS = [
        'withdrawal_qr' => 'QR code / bon de retrait (agent, GAB)',
        'withdrawal_wallet' => 'Wallet (cash-out agent, vers mobile money)',
        'bank_transfer' => 'Virement bancaire (règlement marchand)',
        'deposit_agent' => 'Cash chez un agent',
        'deposit_card' => 'Carte prépayée / bancaire',
        'deposit_mm' => 'Mobile money → wallet',
        'payment_wallet' => 'Wallet (QR, code, NFC)',
        'payment_mm' => 'Mobile money (dont USSD)',
        'payment_bank' => 'Banque / carte',
        'transfer_wallet' => 'Wallet → wallet',
        'transfer_to_mm' => 'Wallet → mobile money',
        'interop' => 'Interopérabilité (MTN → Airtel, pays → pays…)',
        'card_transfer' => 'Envoi payé par carte',
        'agent_float' => 'Approvisionnement float agent',
        'refund' => 'Remboursement marchand / litige',
        'adjustment' => 'Ajustement manuel (back-office)',
    ];

    /** Statuts affichés : rejetée = remboursée / annulée (statut "reversed"). */
    public const STATUSES = [
        'successful' => 'Réussies',
        'failed' => 'Échouées',
        'reversed' => 'Rejetées',
        'processing' => 'En attente',
    ];

    /** Expression SQL (MySQL / SQLite) qui calcule le canal d'une transaction. */
    public static function sql(): string
    {
        $pay = "'qr_payment','merchant_payment','nfc_payment','manual_payment','ecommerce_payment','mini_program_payment'";
        $bank = "'card','bank'";
        return "CASE
            WHEN type = 'float_topup' THEN 'agent_float'
            WHEN type = 'refund' THEN 'refund'
            WHEN type = 'adjustment' THEN 'adjustment'
            WHEN type IN ('gift','gift_claim','gift_refund','split_payment') THEN 'transfer_wallet'
            WHEN type IN ('cash_pickup','atm_withdrawal') THEN 'withdrawal_qr'
            WHEN type IN ('cash_out','withdrawal') THEN 'withdrawal_wallet'
            WHEN type = 'bank_transfer' THEN 'bank_transfer'
            WHEN type = 'cash_in' AND source_rail = 'wallet' THEN 'deposit_agent'
            WHEN type = 'cash_in' AND source_rail IN ({$bank}) THEN 'deposit_card'
            WHEN type = 'cash_in' THEN 'deposit_mm'
            WHEN type = 'collection' THEN 'payment_mm'
            WHEN type IN ({$pay}) AND source_rail = 'wallet' THEN 'payment_wallet'
            WHEN type IN ({$pay}) AND source_rail IN ({$bank}) THEN 'payment_bank'
            WHEN type IN ({$pay}) THEN 'payment_mm'
            WHEN source_rail = 'card' THEN 'card_transfer'
            WHEN source_rail <> 'wallet' AND destination_rail = 'wallet' THEN 'deposit_mm'
            WHEN source_rail <> 'wallet' THEN 'interop'
            WHEN destination_rail <> 'wallet' THEN 'transfer_to_mm'
            ELSE 'transfer_wallet'
        END";
    }

    public static function familyOf(string $channel): ?string
    {
        foreach (self::FAMILIES as $key => $f) {
            if (in_array($channel, $f['channels'], true)) {
                return $key;
            }
        }
        return null;
    }

    /** Canaux d'une famille ou canal seul (filtre ?channel=payments ou ?channel=payment_wallet). */
    public static function expand(string $key): array
    {
        return self::FAMILIES[$key]['channels'] ?? (isset(self::LABELS[$key]) ? [$key] : []);
    }
}

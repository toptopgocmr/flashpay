<?php

namespace App\Services\Notifications;

use App\Models\Agent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

/**
 * Traduit chaque changement de statut de transaction en notifications
 * pour les parties concernées (§11.1 client, §11.2 agent, §11.3 marchand).
 */
class TransactionNotifier
{
    public const MERCHANT_TYPES = ['qr_payment', 'nfc_payment', 'merchant_payment', 'collection', 'ecommerce_payment', 'mini_program_payment', 'manual_payment'];

    public function __construct(protected NotificationService $notify)
    {
    }

    public function handle(Transaction $tx): void
    {
        try {
            match ($tx->status) {
                'successful' => $this->succeeded($tx),
                'failed', 'reversed' => $this->failed($tx),
                default => null,
            };
            $this->checkLowFloat($tx);
        } catch (\Throwable $e) {
            Log::warning("Notification transaction {$tx->reference} : " . $e->getMessage());
        }
    }

    protected function succeeded(Transaction $tx): void
    {
        $src = $this->owner($tx->source_wallet_id);
        $dst = $this->owner($tx->destination_wallet_id);
        $amount = $this->fmt($tx->amount, $tx->currency);
        $received = $this->fmt((int) ($tx->destination_amount ?? $tx->amount) - (int) $tx->merchant_fee, $tx->destination_currency ?: $tx->currency);
        $meta = $tx->meta ?? [];
        $data = ['transaction_id' => $tx->id, 'reference' => $tx->reference, 'type' => $tx->type];

        if (in_array($tx->type, self::MERCHANT_TYPES, true)) {
            $channel = $this->channelLabel($tx);
            $this->notify->toUser($dst, 'payment_received', "Paiement reçu : {$received}", trim(($meta['payer_name'] ?? 'Client') . " · {$channel}"), ['sound' => true, 'severity' => 'success', 'data' => $data]);
            if (! empty($meta['cashier_user_id']) && ($cashier = User::find($meta['cashier_user_id']))) {
                $this->notify->toUser($cashier, 'payment_received', "Encaissement : {$received}", $channel, ['sound' => true, 'severity' => 'success', 'data' => $data]);
            }
            if ($src && $src->id !== $dst?->id) {
                $this->notify->toUser($src, 'payment_sent', "Paiement effectué : {$amount}", $meta['merchant_name'] ?? null, ['severity' => 'success', 'data' => $data]);
            }
            return;
        }

        match ($tx->type) {
            'p2p', 'gift_claim', 'split_payment' => [
                $this->notify->toUser($dst, 'money_received', "Vous avez reçu {$received}", 'De ' . ($meta['sender_name'] ?? $src?->full_name ?? 'un utilisateur FlashPay') . (! empty($meta['note']) ? " — {$meta['note']}" : ''), ['severity' => 'success', 'data' => $data]),
                $tx->type === 'p2p' ? $this->notify->toUser($src, 'money_sent', "Envoi effectué : {$amount}", 'Vers ' . ($meta['beneficiary_name'] ?? $tx->destination_account ?? $dst?->full_name ?? ''), ['data' => $data]) : null,
            ],
            'cash_in' => [
                $this->notify->toUser($dst, 'cash_in', "Recharge de {$received} créditée", ($meta['channel'] ?? null) === 'agent' ? 'Chez l\'agent ' . ($meta['agent_name'] ?? '') : null, ['severity' => 'success', 'data' => $data]),
                ($meta['channel'] ?? null) === 'agent' ? $this->notify->toUser($src, 'agent_cash_in', "Dépôt client confirmé : {$amount}", ($meta['client_name'] ?? '') . ' — reçu numérique disponible', ['severity' => 'success', 'data' => $data, 'sms' => false]) : null,
            ],
            'cash_pickup' => [
                $this->notify->toUser($src, 'cash_out', "Retrait de {$amount} confirmé", 'Espèces remises par ' . ($meta['agent_name'] ?? 'l\'agent'), ['data' => $data]),
                $this->notify->toUser($dst, 'agent_cash_out', "Retrait client confirmé : {$amount}", 'Commission : ' . $this->fmt((int) ($meta['agent_commission'] ?? 0), $tx->currency), ['severity' => 'success', 'data' => $data, 'sms' => false]),
            ],
            'atm_withdrawal' => $this->notify->toUser($src, 'cash_out', "Retrait GAB de {$amount} effectué", null, ['data' => $data]),
            'withdrawal' => $this->notify->toUser($src, 'withdrawal', "Retrait de {$amount} effectué", 'Vers ' . ($tx->destination_account ?? 'votre compte'), ['data' => $data]),
            'bank_transfer' => $this->notify->toUser($src, 'withdrawal_status', "Virement de {$amount} exécuté", $meta['bank_name'] ?? null, ['severity' => 'success', 'data' => $data]),
            'float_topup' => $this->notify->toUser($dst, 'float_topup', "Float crédité : {$received}", 'Approvisionnement validé', ['severity' => 'success', 'data' => $data]),
            'refund' => $this->notify->toUser($dst, 'refund_received', "Remboursement reçu : {$received}", $meta['merchant_name'] ?? null, ['severity' => 'success', 'data' => $data]),
            'adjustment' => null, // notifié par le service d'ajustement (motif)
            default => null,
        };
    }

    protected function failed(Transaction $tx): void
    {
        $src = $this->owner($tx->source_wallet_id) ?? User::find($tx->initiated_by);
        $label = $tx->status === 'reversed' ? 'annulée — montant remboursé' : 'échouée';
        $this->notify->toUser($src, 'transaction_failed', "Opération {$label}", $tx->failure_reason, [
            'severity' => 'warning', 'data' => ['transaction_id' => $tx->id, 'reference' => $tx->reference], 'sms' => false,
        ]);
        if (in_array($tx->type, ['bank_transfer', 'withdrawal'], true) && ($m = $this->owner($tx->source_wallet_id)) && $m->hasRole('merchant')) {
            // statut de retrait marchand (§11.3)
            $this->notify->toUser($m, 'withdrawal_status', 'Retrait rejeté', $tx->failure_reason, ['severity' => 'warning', 'sms' => false]);
        }
    }

    /** Alerte de float bas (§11.2), au plus une fois toutes les 6 heures. */
    protected function checkLowFloat(Transaction $tx): void
    {
        foreach ([$tx->source_wallet_id, $tx->destination_wallet_id] as $wid) {
            $w = $wid ? Wallet::find($wid) : null;
            $agent = $w ? Agent::where('user_id', $w->user_id)->first() : null;
            if (! $agent || $w->balance >= $agent->low_float_threshold) {
                continue;
            }
            if ($agent->low_float_alerted_at && $agent->low_float_alerted_at->gt(now()->subHours(6))) {
                continue;
            }
            $agent->update(['low_float_alerted_at' => now()]);
            $this->notify->toUser($w->user, 'low_float', 'Float bas : ' . $this->fmt($w->balance, $w->currency), 'Pensez à faire une demande d\'approvisionnement.', ['severity' => 'warning', 'sms' => false]);
        }
    }

    protected function owner(?int $walletId): ?User
    {
        return $walletId ? Wallet::find($walletId)?->user : null;
    }

    public function channelLabel(Transaction $tx): string
    {
        return match ($tx->meta['method'] ?? $tx->type) {
            'qr', 'qr_payment' => 'QR code',
            'dynamic_qr' => 'QR dynamique',
            'pay_code' => 'Code de paiement',
            'nfc', 'nfc_payment' => 'NFC',
            'ussd', 'collection' => 'USSD mobile money',
            'manual', 'payment_link' => 'Saisie manuelle / lien',
            'ecommerce', 'ecommerce_payment' => 'E-commerce / API',
            'mini_program', 'mini_program_payment' => 'Mini-programme',
            default => 'Paiement',
        };
    }

    public function fmt(int $amount, ?string $cur): string
    {
        return number_format($amount, 0, ',', ' ') . ' ' . ($cur ?: 'XAF');
    }
}

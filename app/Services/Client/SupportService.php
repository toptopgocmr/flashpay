<?php

namespace App\Services\Client;

use App\Exceptions\BusinessException;
use App\Models\Dispute;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Merchant\RefundService;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\TransactionNotifier;
use App\Services\Ops\WalletAdjustmentService;
use App\Support\Audit;
use Illuminate\Support\Str;

/**
 * Support client (§16) et litiges / contestations (§13.2) avec délais cibles (SLA).
 */
class SupportService
{
    /** Délais de réponse cibles en heures par type de demande (indicatifs). */
    public const TICKET_SLA = ['security_report' => 2, 'blocking_incident' => 4, 'account' => 24, 'kyc' => 48, 'information' => 72];
    public const DISPUTE_SLA = ['cash_out_not_received' => 24, 'cash_in_not_credited' => 24, 'transfer_not_received' => 24, 'withdrawal_issue' => 24, 'unrecognized' => 48, 'wrong_amount' => 72, 'gift_issue' => 72, 'split_issue' => 72, 'merchant_not_delivered' => 120, 'other' => 120];

    public const DISPUTE_REASONS = [
        'unrecognized' => 'Paiement non reconnu',
        'wrong_amount' => 'Montant erroné',
        'cash_out_not_received' => 'Cash-out non reçu',
        'cash_in_not_credited' => 'Dépôt non crédité',
        'merchant_not_delivered' => 'Bien / service non fourni',
        'transfer_not_received' => 'Envoi non reçu par le bénéficiaire',
        'withdrawal_issue' => 'Problème de retrait',
        'gift_issue' => 'Problème de cadeau',
        'split_issue' => 'Problème de note partagée',
        'other' => 'Autre',
    ];

    public function __construct(protected NotificationService $notify)
    {
    }

    // ------------------------------------------------------------ Tickets

    public function openTicket(User $user, string $category, string $subject, string $body, ?string $priority = null): SupportTicket
    {
        $t = SupportTicket::create([
            'reference' => 'TK-' . strtoupper(Str::random(8)),
            'user_id' => $user->id,
            'category' => $category,
            'subject' => mb_substr($subject, 0, 150),
            'priority' => $priority ?? (in_array($category, ['security_report', 'blocking_incident'], true) ? 'high' : 'normal'),
            'sla_due_at' => now()->addHours(self::TICKET_SLA[$category] ?? 72),
            'status' => 'open',
        ]);
        SupportMessage::create(['support_ticket_id' => $t->id, 'author_id' => $user->id, 'body' => $body]);

        // Escalade vers l'équipe anti-fraude pour les signalements de sécurité
        $this->notify->toAdmins($category === 'security_report' ? 'security_report' : 'support_ticket', "Ticket {$t->reference} : {$t->subject}", "{$user->full_name} ({$user->phone})", [
            'severity' => $category === 'security_report' ? 'critical' : 'info', 'data' => ['ticket_id' => $t->id],
        ]);
        return $t->load('messages');
    }

    public function reply(SupportTicket $t, User $author, string $body, bool $staff, ?string $status = null): SupportTicket
    {
        SupportMessage::create(['support_ticket_id' => $t->id, 'author_id' => $author->id, 'from_staff' => $staff, 'body' => $body]);
        $t->update(['status' => $status ?? ($staff ? 'pending_user' : 'open'), 'assigned_to' => $staff ? ($t->assigned_to ?? $author->id) : $t->assigned_to]);
        if ($staff) {
            $this->notify->toUser($t->user, 'support_reply', "Réponse du support ({$t->reference})", mb_substr($body, 0, 140), ['sms' => false]);
        }
        return $t->fresh('messages.author:id,full_name');
    }

    // ------------------------------------------------------------ Litiges

    public function openDispute(User $user, Transaction $tx, string $reason, ?string $description): Dispute
    {
        $wallets = Wallet::where('user_id', $user->id)->pluck('id')->all();
        $party = $tx->initiated_by === $user->id || in_array($tx->source_wallet_id, $wallets, true) || in_array($tx->destination_wallet_id, $wallets, true);
        if (! $party) {
            throw new BusinessException('Vous ne pouvez contester que vos propres opérations.', 'forbidden', 403);
        }
        if (Dispute::where('transaction_id', $tx->id)->whereIn('status', ['open', 'investigating'])->exists()) {
            throw new BusinessException('Une contestation est déjà en cours pour cette opération.', 'dispute_exists');
        }
        if ($tx->created_at->lt(now()->subDays(90))) {
            throw new BusinessException('Délai de contestation dépassé (90 jours).', 'dispute_window');
        }

        $d = Dispute::create([
            'reference' => 'LT-' . strtoupper(Str::random(8)),
            'transaction_id' => $tx->id,
            'user_id' => $user->id,
            'reason' => $reason,
            'description' => $description,
            'sla_due_at' => now()->addHours(self::DISPUTE_SLA[$reason] ?? 120),
            'status' => 'open',
        ]);
        $this->notify->toAdmins('dispute_opened', "Contestation {$d->reference} : " . self::DISPUTE_REASONS[$reason], "{$tx->reference} — {$user->full_name}", ['severity' => 'warning', 'data' => ['dispute_id' => $d->id]]);
        $this->notify->toUser($user, 'dispute_opened', "Contestation {$d->reference} enregistrée", 'Traitement sous ' . (self::DISPUTE_SLA[$reason] ?? 120) . ' h maximum.', ['sms' => false]);
        if ($other = $this->counterpartyWallet($d)?->user) {
            $this->notify->toUser($other, 'dispute_received', "Opération contestée : {$tx->reference}", "{$user->full_name} conteste l'opération (" . self::DISPUTE_REASONS[$reason] . '). Vous pouvez rembourser ou répondre depuis « Contestations reçues ».', ['severity' => 'warning', 'data' => ['dispute_id' => $d->id]]);
        }
        return $d;
    }

    /**
     * Wallet de la partie adverse : celui qui a reçu (ou envoyé) l'argent dans
     * l'opération contestée — marchand, agent ou autre client. Null si l'autre
     * partie est externe (numéro mobile money, banque).
     */
    public function counterpartyWallet(Dispute $d): ?Wallet
    {
        $tx = $d->transaction;
        $mine = Wallet::where('user_id', $d->user_id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $src = (int) $tx->source_wallet_id;
        $dst = (int) $tx->destination_wallet_id;
        $other = in_array($src, $mine, true) ? $dst : (in_array($dst, $mine, true) ? $src : ($dst ?: $src));
        return $other && ! in_array($other, $mine, true) ? Wallet::with('user')->find($other) : null;
    }

    /** Transfert wallet → wallet tracé (remboursement d'un litige par la partie adverse). */
    public function refundFromWallet(Dispute $d, Wallet $from, int $amount, User $by, string $reason): Transaction
    {
        $to = $d->user->wallet ?? throw new BusinessException('Wallet du plaignant introuvable.');
        if ($from->id === $to->id) {
            throw new BusinessException('Wallet source et destination identiques.');
        }
        $wallets = app(\App\Services\WalletService::class);
        $ledger = app(\App\Services\LedgerService::class);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($d, $from, $to, $amount, $by, $reason, $wallets, $ledger) {
            $tx = Transaction::create([
                'reference' => 'FP-' . Str::upper(Str::random(12)),
                'type' => 'refund', 'scope' => 'national',
                'source_rail' => 'wallet', 'destination_rail' => 'wallet',
                'source_wallet_id' => $from->id, 'destination_wallet_id' => $to->id,
                'amount' => $amount, 'currency' => $to->currency,
                'status' => 'processing', 'initiated_by' => $by->id,
                'meta' => ['channel' => 'dispute_refund', 'dispute' => $d->reference, 'original' => $d->transaction->reference, 'reason' => $reason,
                    'sender_name' => $from->user?->full_name, 'beneficiary_name' => $d->user->full_name],
            ]);
            $wallets->debit($from, $amount);
            $wallets->credit($to, $amount);
            $ledger->recordDoubleEntry($tx, "wallet:{$from->id}", "wallet:{$to->id}", $amount, null, "Remboursement litige {$d->reference}");
            $tx->update(['status' => 'successful', 'completed_at' => now()]);
            return $tx;
        });
    }

    /** La partie adverse (marchand, agent, client) rembourse elle-même depuis son wallet. */
    public function counterpartyRefund(Dispute $d, User $by, ?int $amount, ?string $message): Dispute
    {
        $wallet = $this->counterpartyWallet($d);
        if (! $wallet || (int) $wallet->user_id !== (int) $by->id) {
            throw new BusinessException("Cette contestation ne concerne pas votre compte.", 'forbidden', 403);
        }
        return $this->resolveDispute($d, $by, 'refund', $message ?: 'Remboursé par ' . $by->full_name, $amount, 'counterparty');
    }

    /** Réponse de la partie adverse (contestation du litige) : l'administrateur arbitrera. */
    public function counterpartyRespond(Dispute $d, User $by, string $message): Dispute
    {
        $wallet = $this->counterpartyWallet($d);
        if (! $wallet || (int) $wallet->user_id !== (int) $by->id) {
            throw new BusinessException("Cette contestation ne concerne pas votre compte.", 'forbidden', 403);
        }
        if (! in_array($d->status, ['open', 'investigating'], true)) {
            throw new BusinessException('Litige déjà clôturé.', 'dispute_closed');
        }
        $d->update(['counterparty_response' => $message, 'counterparty_responded_at' => now(), 'status' => 'investigating']);
        $this->notify->toAdmins('dispute_response', "Réponse sur la contestation {$d->reference}", "{$by->full_name} : {$message}", ['severity' => 'info', 'data' => ['dispute_id' => $d->id]]);
        $this->notify->toUser($d->user, 'dispute_update', "Contestation {$d->reference} en cours d'arbitrage", "{$by->full_name} a répondu ; FlashPay va trancher.", ['sms' => false]);
        return $d->fresh();
    }

    /**
     * Arbitrage administrateur (§13.2) : rejet ou remboursement. Paiement marchand →
     * remboursement depuis le wallet du marchand ; autre opération → correction
     * exceptionnelle tracée (crédit depuis le compte de régularisation).
     */
    /**
     * @param string|null $source  auto | counterparty (wallet de la partie adverse) | flashpay (correction)
     */
    public function resolveDispute(Dispute $d, User $admin, string $decision, string $resolution, ?int $amount = null, ?string $source = null): Dispute
    {
        if (! in_array($d->status, ['open', 'investigating'], true)) {
            throw new BusinessException('Litige déjà clôturé.', 'dispute_closed');
        }
        $refundTxId = null;
        $from = null;
        if ($decision === 'refund') {
            $tx = $d->transaction;
            $amount ??= (int) $tx->amount;
            if ($amount > (int) $tx->amount + (int) $tx->fee) {
                throw new BusinessException('Le remboursement ne peut pas dépasser le montant de l\'opération (frais inclus).', 'amount_too_high');
            }
            if ($source === 'counterparty') {
                $wallet = $this->counterpartyWallet($d) ?? throw new BusinessException("Pas de wallet FlashPay pour l'autre partie : remboursez depuis FlashPay.", 'no_counterparty');
                $refundTxId = $this->refundFromWallet($d, $wallet, $amount, $admin, $resolution)->id;
                $from = 'counterparty';
            } elseif ($source === 'flashpay') {
                $wallet = $d->user->wallet ?? throw new BusinessException('Wallet du client introuvable.');
                $refundTxId = app(WalletAdjustmentService::class)->adjust($wallet, 'credit', $amount, "Litige {$d->reference} : {$resolution}", $admin)->id;
                $from = 'flashpay';
            } elseif (in_array($tx->type, TransactionNotifier::MERCHANT_TYPES, true)) {
                $from = 'merchant';
                $refundTxId = app(RefundService::class)->refund($tx, $amount, 'admin', $admin, "Litige {$d->reference}")->refund_transaction_id;
            } else {
                $wallet = $d->user->wallet ?? throw new BusinessException('Wallet du client introuvable.');
                $refundTxId = app(WalletAdjustmentService::class)->adjust($wallet, 'credit', $amount, "Litige {$d->reference} : {$resolution}", $admin)->id;
                $from = 'flashpay';
            }
        }
        $d->update([
            'status' => $decision === 'refund' ? 'resolved_refunded' : 'resolved_rejected',
            'resolution' => $resolution, 'resolved_by' => $admin->id, 'resolved_at' => now(),
            'refund_transaction_id' => $refundTxId,
            'refunded_from' => $from,
        ]);
        Audit::log('dispute.resolve', $d, ['decision' => $decision, 'amount' => $amount, 'resolution' => $resolution, 'from' => $from], $admin->id);
        if ($from === 'counterparty' && ($payer = $this->counterpartyWallet($d)?->user) && $payer->id !== $admin->id) {
            $this->notify->toUser($payer, 'dispute_debit', "Remboursement du litige {$d->reference}", number_format((int) $amount, 0, ',', ' ') . " débités de votre wallet au profit de {$d->user->full_name}. {$resolution}", ['severity' => 'warning']);
        }
        $this->notify->toUser($d->user, 'dispute_resolved', "Contestation {$d->reference} traitée", ($decision === 'refund' ? 'Remboursement effectué. ' : 'Contestation non retenue. ') . $resolution, ['severity' => $decision === 'refund' ? 'success' : 'info']);
        return $d->fresh();
    }
}

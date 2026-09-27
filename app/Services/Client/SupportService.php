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
    public const DISPUTE_SLA = ['cash_out_not_received' => 24, 'cash_in_not_credited' => 24, 'unrecognized' => 48, 'wrong_amount' => 72, 'merchant_not_delivered' => 120, 'other' => 120];

    public const DISPUTE_REASONS = [
        'unrecognized' => 'Paiement non reconnu',
        'wrong_amount' => 'Montant erroné',
        'cash_out_not_received' => 'Cash-out non reçu',
        'cash_in_not_credited' => 'Dépôt non crédité',
        'merchant_not_delivered' => 'Bien / service non fourni',
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
        return $d;
    }

    /**
     * Arbitrage administrateur (§13.2) : rejet ou remboursement. Paiement marchand →
     * remboursement depuis le wallet du marchand ; autre opération → correction
     * exceptionnelle tracée (crédit depuis le compte de régularisation).
     */
    public function resolveDispute(Dispute $d, User $admin, string $decision, string $resolution, ?int $amount = null): Dispute
    {
        if (! in_array($d->status, ['open', 'investigating'], true)) {
            throw new BusinessException('Litige déjà clôturé.', 'dispute_closed');
        }
        $refundTxId = null;
        if ($decision === 'refund') {
            $tx = $d->transaction;
            $amount ??= $tx->amount;
            if (in_array($tx->type, TransactionNotifier::MERCHANT_TYPES, true)) {
                $refundTxId = app(RefundService::class)->refund($tx, $amount, 'admin', $admin, "Litige {$d->reference}")->refund_transaction_id;
            } else {
                $wallet = $d->user->wallet ?? throw new BusinessException('Wallet du client introuvable.');
                $refundTxId = app(WalletAdjustmentService::class)->adjust($wallet, 'credit', $amount, "Litige {$d->reference} : {$resolution}", $admin)->id;
            }
        }
        $d->update([
            'status' => $decision === 'refund' ? 'resolved_refunded' : 'resolved_rejected',
            'resolution' => $resolution, 'resolved_by' => $admin->id, 'resolved_at' => now(),
            'refund_transaction_id' => $refundTxId,
        ]);
        Audit::log('dispute.resolve', $d, ['decision' => $decision, 'amount' => $amount, 'resolution' => $resolution], $admin->id);
        $this->notify->toUser($d->user, 'dispute_resolved', "Contestation {$d->reference} traitée", ($decision === 'refund' ? 'Remboursement effectué. ' : 'Contestation non retenue. ') . $resolution, ['severity' => $decision === 'refund' ? 'success' : 'info']);
        return $d->fresh();
    }
}

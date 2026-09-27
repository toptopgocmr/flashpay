<?php

namespace App\Services\Agent;

use App\Exceptions\BusinessException;
use App\Models\Agent;
use App\Models\FloatRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\TransactionNotifier;
use App\Services\WalletService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demandes d'approvisionnement du float (§3.1.2, §3.4.3).
 *   cash_deposit / bank_transfer : validées par le back-office FlashPay (trésorerie → float)
 *   super_agent                  : validées par le super-agent (son float → float de l'agent)
 */
class FloatRequestService
{
    public function __construct(
        protected WalletService $wallets,
        protected LedgerService $ledger,
        protected NotificationService $notify,
        protected CommissionService $commissions,
    ) {
    }

    public function create(User $agentUser, array $d): FloatRequest
    {
        $agent = $agentUser->agent;
        if (! $agent || $agent->validation_status !== 'approved') {
            throw new BusinessException('Compte agent non validé.', 'agent_not_approved', 403);
        }
        $super = null;
        if ($d['method'] === 'super_agent') {
            $super = ! empty($d['super_agent_code'])
                ? Agent::where('agent_code', strtoupper($d['super_agent_code']))->first()
                : $agent->parent;
            if (! $super || ! $super->is_super_agent || $super->id === $agent->id) {
                throw new BusinessException('Super-agent introuvable. Vérifiez son identifiant agent.', 'super_agent_not_found');
            }
        }
        if (FloatRequest::where('agent_id', $agent->id)->where('status', 'pending')->count() >= 3) {
            throw new BusinessException('Vous avez déjà 3 demandes en attente.', 'too_many_pending');
        }

        $req = FloatRequest::create([
            'agent_id' => $agent->id,
            'super_agent_id' => $super?->id,
            'amount' => $d['amount'],
            'currency' => $agentUser->wallet?->currency ?? 'XAF',
            'method' => $d['method'],
            'proof_reference' => $d['proof_reference'] ?? null,
            'note' => $d['note'] ?? null,
            'status' => 'pending',
        ]);

        $label = number_format($req->amount, 0, ',', ' ') . ' ' . $req->currency;
        if ($super) {
            $this->notify->toUser($super->user, 'float_request', "Demande d'approvisionnement : {$label}", "De l'agent {$agentUser->full_name} ({$agent->agent_code})", ['data' => ['float_request_id' => $req->id]]);
        } else {
            $this->notify->toAdmins('float_request', "Demande d'approvisionnement agent : {$label}", "{$agentUser->full_name} ({$agent->agent_code}) — " . $this->methodLabel($req->method), ['data' => ['float_request_id' => $req->id]]);
        }
        $this->notify->toUser($agentUser, 'float_request_status', 'Demande d\'approvisionnement envoyée', "{$label} — en attente de validation", ['sms' => false]);

        return $req;
    }

    public function approve(FloatRequest $req, User $reviewer, ?int $amount = null): FloatRequest
    {
        return DB::transaction(function () use ($req, $reviewer, $amount) {
            $r = FloatRequest::whereKey($req->id)->lockForUpdate()->first();
            if ($r->status !== 'pending') {
                throw new BusinessException('Cette demande a déjà été traitée.', 'already_processed');
            }
            $this->authorizeReviewer($r, $reviewer);

            $amount = $amount ?: $r->amount;
            $agentWallet = $r->agent->user->wallet ?? throw new BusinessException('Wallet agent introuvable.');

            $tx = Transaction::create([
                'reference' => 'FP-' . Str::upper(Str::random(12)),
                'type' => 'float_topup',
                'scope' => 'national',
                'source_rail' => $r->method === 'super_agent' ? 'wallet' : 'treasury',
                'destination_rail' => 'wallet',
                'destination_wallet_id' => $agentWallet->id,
                'amount' => $amount,
                'currency' => $agentWallet->currency,
                'status' => 'processing',
                'initiated_by' => $reviewer->id,
                'meta' => array_filter(['channel' => 'float', 'agent_id' => $r->agent_id, 'float_request_id' => $r->id, 'method' => $r->method, 'proof_reference' => $r->proof_reference]),
            ]);

            if ($r->method === 'super_agent') {
                $superWallet = $r->superAgent->user->wallet;
                if ($superWallet->currency !== $agentWallet->currency) {
                    throw new BusinessException('Devise du super-agent différente.');
                }
                $this->wallets->debit($superWallet, $amount);
                $this->wallets->credit($agentWallet, $amount);
                $tx->update(['source_wallet_id' => $superWallet->id]);
                $this->ledger->recordDoubleEntry($tx, "wallet:{$superWallet->id}", "wallet:{$agentWallet->id}", $amount, null, 'Approvisionnement par super-agent');
                $this->commissions->pay($tx, $superWallet, $this->commissions->compute('float_request', $amount), 'float_request');
            } else {
                $this->wallets->credit($agentWallet, $amount);
                $this->ledger->recordDoubleEntry($tx, 'flashpay:float', "wallet:{$agentWallet->id}", $amount, null, 'Approvisionnement float agent (' . $this->methodLabel($r->method) . ')');
            }

            $tx->update(['status' => 'successful', 'completed_at' => now()]);
            $r->update(['status' => 'approved', 'amount' => $amount, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'transaction_id' => $tx->id]);
            Audit::log('float_request.approve', $r, ['amount' => $amount, 'method' => $r->method], $reviewer->id);
            app(TransactionNotifier::class)->handle($tx->fresh());

            return $r->fresh(['agent.user', 'transaction']);
        });
    }

    public function reject(FloatRequest $req, User $reviewer, string $reason): FloatRequest
    {
        if ($req->status !== 'pending') {
            throw new BusinessException('Cette demande a déjà été traitée.', 'already_processed');
        }
        $this->authorizeReviewer($req, $reviewer);
        $req->update(['status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
        Audit::log('float_request.reject', $req, ['reason' => $reason], $reviewer->id);
        $this->notify->toUser($req->agent->user, 'float_request_status', 'Demande d\'approvisionnement rejetée', $reason, ['severity' => 'warning', 'sms' => false]);
        return $req->fresh();
    }

    public function methodLabel(string $m): string
    {
        return ['cash_deposit' => 'Dépôt cash', 'bank_transfer' => 'Virement bancaire', 'super_agent' => 'Super-agent'][$m] ?? $m;
    }

    protected function authorizeReviewer(FloatRequest $r, User $reviewer): void
    {
        if ($reviewer->hasRole('super_admin')) {
            return;
        }
        if ($r->method === 'super_agent' && $reviewer->agent && $reviewer->agent->id === $r->super_agent_id) {
            return;
        }
        throw new BusinessException('Vous ne pouvez pas traiter cette demande.', 'forbidden', 403);
    }
}

<?php

namespace App\Services\Client;

use App\Exceptions\BusinessException;
use App\Models\BillSplit;
use App\Models\BillSplitShare;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use App\Services\SwitchService;
use Illuminate\Support\Facades\DB;

/** Partage de note entre utilisateurs (§3.5.2) : parts égales ou personnalisées, relances. */
class SplitBillService
{
    public function __construct(
        protected NotificationService $notify,
        protected PeexFlowService $flows,
        protected PeexCorridors $corridors,
        protected SwitchService $switch,
    ) {
    }

    public function create(User $creator, array $d): BillSplit
    {
        $wallet = $this->flows->walletOf($creator);
        $parts = collect($d['participants'])->map(fn ($p) => [
            'phone' => $this->corridors->resolve((string) $p['phone'])['phone'],
            'amount' => isset($p['amount']) ? (int) $p['amount'] : null,
        ])->unique('phone')->reject(fn ($p) => ltrim($p['phone'], '+') === ltrim($creator->phone, '+'))->values();
        if ($parts->isEmpty()) {
            throw new BusinessException('Ajoutez au moins un participant.', 'no_participant');
        }
        $total = (int) $d['total_amount'];
        $includeSelf = (bool) ($d['include_self'] ?? true);

        if ($d['mode'] === 'equal') {
            $n = $parts->count() + ($includeSelf ? 1 : 0);
            $each = intdiv($total, $n);
            $parts = $parts->map(fn ($p) => ['phone' => $p['phone'], 'amount' => $each]);
            $selfAmount = $includeSelf ? $total - $each * $parts->count() : 0;
        } else {
            $sum = (int) $parts->sum('amount');
            if ($parts->contains(fn ($p) => ! $p['amount'] || $p['amount'] <= 0) || $sum > $total) {
                throw new BusinessException('Parts personnalisées invalides (somme supérieure au total).', 'invalid_shares');
            }
            $selfAmount = $total - $sum;
        }

        return DB::transaction(function () use ($creator, $wallet, $d, $total, $parts, $selfAmount) {
            $split = BillSplit::create(['creator_id' => $creator->id, 'title' => $d['title'], 'total_amount' => $total, 'currency' => $wallet->currency, 'mode' => $d['mode'], 'status' => 'open']);
            if ($selfAmount > 0) {
                BillSplitShare::create(['bill_split_id' => $split->id, 'user_id' => $creator->id, 'phone' => $creator->phone, 'amount' => $selfAmount, 'status' => 'self']);
            }
            foreach ($parts as $p) {
                $u = $this->flows->findUserByPhone($p['phone']);
                $share = BillSplitShare::create(['bill_split_id' => $split->id, 'user_id' => $u?->id, 'phone' => $p['phone'], 'amount' => $p['amount'], 'status' => 'pending']);
                $this->request($split, $share, $creator);
            }
            return $split->load('shares');
        });
    }

    protected function request(BillSplit $split, BillSplitShare $share, User $creator, bool $reminder = false): void
    {
        $amount = number_format($share->amount, 0, ',', ' ') . " {$split->currency}";
        if ($share->user) {
            $this->notify->toUser($share->user, 'split_request', ($reminder ? 'Rappel : ' : '') . "{$creator->full_name} vous demande {$amount}", "Partage : {$split->title}", ['data' => ['bill_split_id' => $split->id, 'share_id' => $share->id]]);
        } else {
            $this->notify->sms($share->phone, "FlashPay: {$creator->full_name} vous demande {$amount} ({$split->title}). Installez FlashPay pour regler votre part.");
        }
    }

    public function pay(User $user, BillSplitShare $share): BillSplitShare
    {
        $split = $share->split;
        if ($share->status !== 'pending' || $split->status !== 'open') {
            throw new BusinessException('Cette part n\'est plus à régler.', 'share_closed');
        }
        if ($share->user_id !== $user->id && ltrim($share->phone, '+') !== ltrim($user->phone, '+')) {
            throw new BusinessException('Cette part ne vous concerne pas.', 'forbidden', 403);
        }
        $from = $this->flows->walletOf($user);
        $to = $this->flows->walletOf($split->creator);

        $tx = $this->switch->process([
            'type' => 'split_payment', 'scope' => 'national',
            'source_rail' => 'wallet', 'source_wallet_id' => $from->id,
            'destination_rail' => 'wallet', 'destination_wallet_id' => $to->id,
            'amount' => $share->amount, 'currency' => $from->currency,
            'initiated_by' => $user->id,
            'meta' => ['channel' => 'split', 'bill_split_id' => $split->id, 'sender_name' => $user->full_name, 'note' => $split->title, 'beneficiary_name' => $split->creator->full_name],
        ]);
        if ($tx->status !== 'successful') {
            throw new BusinessException($tx->failure_reason ?: 'Paiement de la part impossible.', 'payment_failed');
        }
        $share->update(['status' => 'paid', 'paid_at' => now(), 'user_id' => $user->id, 'transaction_id' => $tx->id]);
        if (! $split->shares()->where('status', 'pending')->exists()) {
            $split->update(['status' => 'settled']);
            $this->notify->toUser($split->creator, 'split_settled', "Partage « {$split->title} » entièrement réglé", null, ['severity' => 'success', 'sms' => false]);
        }
        return $share->fresh();
    }

    public function decline(User $user, BillSplitShare $share): BillSplitShare
    {
        if ($share->status !== 'pending' || ($share->user_id !== $user->id && ltrim($share->phone, '+') !== ltrim($user->phone, '+'))) {
            throw new BusinessException('Action impossible.', 'forbidden', 403);
        }
        $share->update(['status' => 'declined']);
        $this->notify->toUser($share->split->creator, 'split_declined', "{$user->full_name} a refusé sa part", $share->split->title, ['sms' => false]);
        return $share;
    }

    /** Relance des parts impayées (au plus une fois par heure et par part). */
    public function remind(BillSplit $split): int
    {
        $n = 0;
        foreach ($split->shares()->where('status', 'pending')->get() as $share) {
            if ($share->reminded_at && $share->reminded_at->gt(now()->subHour())) {
                continue;
            }
            $this->request($split, $share, $split->creator, true);
            $share->update(['reminded_at' => now()]);
            $n++;
        }
        return $n;
    }

    public function summary(BillSplit $s): array
    {
        $s->loadMissing('shares.user:id,full_name', 'creator:id,full_name');
        return $s->toArray() + [
            'paid_amount' => (int) $s->shares->where('status', 'paid')->sum('amount'),
            'pending_amount' => (int) $s->shares->where('status', 'pending')->sum('amount'),
        ];
    }
}

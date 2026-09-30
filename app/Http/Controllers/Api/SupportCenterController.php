<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Services\Client\SupportService;
use Illuminate\Http\Request;

/** Support & réclamations (§16) et contestations de transactions (§13.2). */
class SupportCenterController extends Controller
{
    public function __construct(protected SupportService $support)
    {
    }

    public function faq(Request $request)
    {
        $lang = in_array($request->query('lang', $request->user()?->language), ['fr', 'en'], true) ? $request->query('lang', $request->user()?->language) : 'fr';
        return response()->json([
            'faq' => config("faq.{$lang}", config('faq.fr')),
            'channels' => config('faq.channels'),
            'sla_hours' => SupportService::TICKET_SLA,
        ]);
    }

    public function tickets(Request $request)
    {
        return response()->json(SupportTicket::with('messages.author:id,full_name')->where('user_id', $request->user()->id)->latest()->limit(50)->get());
    }

    public function openTicket(Request $request)
    {
        $v = $request->validate([
            'category' => 'required|in:' . implode(',', array_keys(SupportService::TICKET_SLA)),
            'subject' => 'required|string|max:150',
            'message' => 'required|string|max:3000',
        ]);
        return response()->json($this->support->openTicket($request->user(), $v['category'], $v['subject'], $v['message']), 201);
    }

    public function replyTicket(Request $request, SupportTicket $ticket)
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        $v = $request->validate(['message' => 'required|string|max:3000']);
        return response()->json($this->support->reply($ticket, $request->user(), $v['message'], false));
    }

    public function disputes(Request $request)
    {
        return response()->json(Dispute::with('transaction:id,reference,type,amount,currency,created_at')->where('user_id', $request->user()->id)->latest()->get()
            ->map(fn ($d) => $d->toArray() + ['reason_label' => SupportService::DISPUTE_REASONS[$d->reason] ?? $d->reason]));
    }

    public function openDispute(Request $request)
    {
        $v = $request->validate([
            'transaction_id' => 'required|integer|exists:transactions,id',
            'reason' => 'required|in:' . implode(',', array_keys(SupportService::DISPUTE_REASONS)),
            'description' => 'nullable|string|max:2000',
        ]);
        return response()->json($this->support->openDispute($request->user(), Transaction::findOrFail($v['transaction_id']), $v['reason'], $v['description'] ?? null), 201);
    }

    /** Contestations visant une opération dont je suis l'autre partie (marchand, agent, client). */
    public function receivedDisputes(Request $request)
    {
        $me = $request->user();
        $walletIds = \App\Models\Wallet::where('user_id', $me->id)->pluck('id');
        $rows = Dispute::with('transaction:id,reference,type,amount,fee,currency,status,created_at,source_wallet_id,destination_wallet_id', 'user:id,full_name,phone')
            ->where('user_id', '!=', $me->id)
            ->whereHas('transaction', fn ($q) => $q->whereIn('source_wallet_id', $walletIds)->orWhereIn('destination_wallet_id', $walletIds))
            ->latest()->limit(100)->get();

        return response()->json($rows->map(fn ($d) => $this->present($d)));
    }

    public function counterpartyRefund(Request $request, Dispute $dispute)
    {
        $v = $request->validate(['amount' => 'nullable|integer|min:1', 'message' => 'nullable|string|max:1000']);
        return response()->json($this->present($this->support->counterpartyRefund($dispute, $request->user(), $v['amount'] ?? null, $v['message'] ?? null)));
    }

    public function counterpartyRespond(Request $request, Dispute $dispute)
    {
        $v = $request->validate(['message' => 'required|string|max:1000']);
        return response()->json($this->present($this->support->counterpartyRespond($dispute, $request->user(), $v['message'])));
    }

    protected function present(Dispute $d): array
    {
        $d->loadMissing('transaction', 'user:id,full_name,phone');
        $other = $this->support->counterpartyWallet($d);
        return $d->toArray() + [
            'reason_label' => SupportService::DISPUTE_REASONS[$d->reason] ?? $d->reason,
            'overdue' => $d->sla_due_at?->isPast() && in_array($d->status, ['open', 'investigating'], true),
            'counterparty' => $other ? [
                'name' => $other->user?->full_name, 'phone' => $other->user?->phone,
                'role' => $other->user?->getRoleNames()->first(), 'balance' => (int) $other->balance, 'currency' => $other->currency,
            ] : null,
        ];
    }

    // ---------------------------------------------------------- Admin / support

    public function adminTickets(Request $request)
    {
        $q = SupportTicket::with('user:id,full_name,phone', 'messages.author:id,full_name')->latest();
        $request->filled('status') ? $q->where('status', $request->input('status')) : $q->whereIn('status', ['open', 'pending_user']);
        if ($request->filled('category')) {
            $q->where('category', $request->input('category'));
        }
        return response()->json($q->paginate(30)->through(fn ($t) => $t->toArray() + ['overdue' => $t->sla_due_at->isPast() && in_array($t->status, ['open', 'pending_user'], true)]));
    }

    public function adminReplyTicket(Request $request, SupportTicket $ticket)
    {
        $v = $request->validate(['message' => 'required|string|max:3000', 'status' => 'nullable|in:open,pending_user,resolved,closed']);
        return response()->json($this->support->reply($ticket, $request->user(), $v['message'], true, $v['status'] ?? null));
    }

    public function adminDisputes(Request $request)
    {
        $q = Dispute::with('transaction', 'user:id,full_name,phone', 'assignee:id,full_name')->latest();
        $request->filled('status') ? $q->where('status', $request->input('status')) : $q->whereIn('status', ['open', 'investigating']);
        return response()->json($q->paginate(30)->through(fn ($d) => $this->present($d)));
    }

    public function adminUpdateDispute(Request $request, Dispute $dispute)
    {
        $v = $request->validate([
            'action' => 'required|in:investigate,refund,reject',
            'resolution' => 'required_unless:action,investigate|nullable|string|max:2000',
            'amount' => 'nullable|integer|min:1',
            'source' => 'nullable|in:auto,counterparty,flashpay',
        ]);
        if ($v['action'] === 'investigate') {
            $dispute->update(['status' => 'investigating', 'assigned_to' => $request->user()->id]);
            return response()->json($dispute->fresh());
        }
        return response()->json($this->present($this->support->resolveDispute($dispute, $request->user(), $v['action'], $v['resolution'], $v['amount'] ?? null, $v['source'] ?? null)));
    }
}

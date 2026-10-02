<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillSplit;
use App\Models\BillSplitShare;
use App\Models\GiftEnvelope;
use App\Models\LinkedAccount;
use App\Models\MiniProgram;
use App\Models\Transaction;
use App\Services\Client\GiftService;
use App\Services\Client\SplitBillService;
use App\Services\Compliance\LimitService;
use App\Services\SettlementService;
use Illuminate\Http\Request;

/**
 * Fonctionnalités client : vue consolidée du wallet et comptes liés (§3.3.5),
 * retrait vers compte bancaire lié (§3.3.3), cadeaux d'argent (§3.5.1),
 * partage de note (§3.5.2), répertoire de mini-programmes (§3.5.3).
 */
class ClientFeaturesController extends Controller
{
    public function overview(Request $request, LimitService $limits)
    {
        $u = $request->user();
        $wallet = $u->wallet;
        return response()->json([
            'wallet' => $wallet,
            'linked_accounts' => $u->linkedAccounts,
            'limits' => $limits->summary($u),
            'recent' => Transaction::where(fn ($q) => $q->where('source_wallet_id', $wallet?->id)->orWhere('destination_wallet_id', $wallet?->id))->latest()->limit(5)->get(),
            'pending_gifts' => \App\Models\GiftClaim::with('envelope.sender:id,full_name')->whereNull('claimed_at')
                ->where(fn ($q) => $q->where('recipient_id', $u->id)->orWhere('recipient_phone', $u->phone))
                ->whereHas('envelope', fn ($q) => $q->where('status', 'active')->where('expires_at', '>', now()))->get(),
            'pending_split_shares' => BillSplitShare::with('split.creator:id,full_name')->where('status', 'pending')
                ->where(fn ($q) => $q->where('user_id', $u->id)->orWhere('phone', $u->phone))->get(),
            'pending_money_requests' => \App\Models\MoneyRequest::with('requester:id,full_name')->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->where(fn ($q) => $q->where('payer_id', $u->id)->orWhereIn('payer_phone', array_map(fn ($p) => ltrim($p, '+'), \App\Support\Phone::candidates($u->phone))))
                ->latest()->limit(10)->get(),
        ]);
    }

    // ------------------------------------------------ Comptes liés

    public function linkedAccounts(Request $request)
    {
        return response()->json($request->user()->linkedAccounts()->orderBy('id')->get());
    }

    public function addLinkedAccount(Request $request)
    {
        $v = $request->validate([
            'type' => 'required|in:mobile_money,bank,card',
            'label' => 'nullable|string|max:80',
            'country' => 'nullable|string|size:2',
            'phone' => 'required_if:type,mobile_money|nullable|string|max:25',
            'bank_name' => 'required_if:type,bank|nullable|string|max:120',
            'account_holder' => 'required_if:type,bank|nullable|string|max:120',
            'account_number' => 'required_if:type,bank|nullable|string|max:60',
            'card_holder' => 'required_if:type,card|nullable|string|max:120',
            'card_number' => ['required_if:type,card', 'nullable', 'string', 'regex:/^[0-9 ]{12,23}$/'],
            'card_expiry' => ['required_if:type,card', 'nullable', 'string', 'regex:/^(0[1-9]|1[0-2])\/[0-9]{2}$/'],
            'is_default' => 'nullable|boolean',
        ]);
        $u = $request->user();
        abort_if($u->linkedAccounts()->count() >= 10, 422, 'Maximum 10 comptes liés.');
        $operator = null;
        $country = strtoupper($v['country'] ?? ($u->wallet?->country ?? 'CG'));
        if ($v['type'] === 'mobile_money') {
            $route = app(\App\Services\Peex\PeexCorridors::class)->resolve($v['phone'], $v['country'] ?? null);
            $v['phone'] = $route['phone'];
            $operator = $route['operator'] ?? null;
            $country = $route['country'];
        }
        $cardFields = [];
        if ($v['type'] === 'card') {
            $digits = preg_replace('/\D/', '', $v['card_number']);
            // PCI-DSS : jamais de PAN complet ni de CVV en base — seuls le
            // réseau et les 4 derniers chiffres sont conservés.
            $cardFields = [
                'account_holder' => $v['card_holder'],
                'card_brand' => $this->cardBrand($digits),
                'card_last4' => substr($digits, -4),
                'card_expiry' => $v['card_expiry'],
            ];
            unset($v['card_number']);
        }
        $acc = LinkedAccount::create(array_intersect_key($v, array_flip(['type', 'label', 'phone', 'bank_name', 'account_holder', 'account_number'])) + $cardFields + [
            'user_id' => $u->id, 'country' => $country, 'operator' => is_array($operator) ? ($operator['label'] ?? null) : $operator,
            // Un compte par défaut PAR TYPE (mobile money, carte, banque) : c'est lui
            // qui est débité par défaut pour les recharges, envois et paiements.
            'is_default' => (bool) ($v['is_default'] ?? ! $u->linkedAccounts()->where('type', $v['type'])->exists()),
        ]);
        if ($acc->is_default) {
            LinkedAccount::where('user_id', $u->id)->where('type', $acc->type)->where('id', '<>', $acc->id)->update(['is_default' => false]);
        }
        return response()->json($acc, 201);
    }

    /** Définit le compte lié débité par défaut pour son type. */
    public function defaultLinkedAccount(Request $request, LinkedAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 403);
        LinkedAccount::where('user_id', $account->user_id)->where('type', $account->type)->update(['is_default' => false]);
        $account->update(['is_default' => true]);
        return response()->json($account->fresh());
    }

    private function cardBrand(string $digits): string
    {
        return match (true) {
            (bool) preg_match('/^4/', $digits) => 'Visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $digits) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $digits) => 'American Express',
            default => 'Carte',
        };
    }

    public function deleteLinkedAccount(Request $request, LinkedAccount $account)
    {
        abort_unless($account->user_id === $request->user()->id, 404);
        $wasDefault = $account->is_default;
        $account->delete();
        if ($wasDefault) {
            // Le plus récent du même type devient le compte débité par défaut.
            LinkedAccount::where('user_id', $account->user_id)->where('type', $account->type)->latest('id')->first()?->update(['is_default' => true]);
        }
        return response()->json(['message' => 'Compte supprimé.']);
    }

    /** Retrait vers un compte bancaire lié (virement à délai différé, traité par le back-office). */
    public function withdrawToBank(Request $request, SettlementService $settlements)
    {
        $v = $request->validate(['linked_account_id' => 'required|integer', 'amount' => 'required|integer|min:1000']);
        $acc = LinkedAccount::where('user_id', $request->user()->id)->where('type', 'bank')->findOrFail($v['linked_account_id']);
        $tx = $settlements->bankTransfer($request->user(), $acc, $v['amount'], ['settlement' => 'client_withdrawal', 'client_name' => $request->user()->full_name]);
        return response()->json($tx->toArray() + ['message' => 'Virement enregistré : délai de traitement 24 à 72 h ouvrées.'], 202);
    }

    // ------------------------------------------------ Cadeaux d'argent

    public function gifts(Request $request, GiftService $gifts)
    {
        $u = $request->user();
        $phones = array_unique(array_merge([$u->phone], array_map(fn ($p) => ltrim($p, '+'), \App\Support\Phone::candidates((string) $u->phone))));
        $sent = GiftEnvelope::with('claims.recipient:id,full_name,phone', 'sender:id,full_name')->where('sender_id', $u->id)->latest()->limit(30)->get()
            ->map(fn ($e) => $gifts->presentSent($e));
        $received = \App\Models\GiftClaim::with('envelope.sender:id,full_name')
            ->where(fn ($q) => $q->where('recipient_id', $u->id)->orWhereIn('recipient_phone', $phones))->latest()->limit(30)->get()
            ->map(fn ($c) => $gifts->presentReceived($c));

        return response()->json([
            'summary' => [
                'to_open' => $received->where('can_open', true)->count(),
                'received_total' => (int) $received->where('claimed', true)->sum('amount'),
                'sent_total' => (int) $sent->sum('claimed_amount'),
                'active_sent' => $sent->where('status', 'active')->count(),
            ],
            'occasions' => GiftService::OCCASIONS,
            'received' => $received->values(),
            'sent' => $sent->values(),
        ]);
    }

    public function cancelGift(Request $request, GiftService $gifts, string $code)
    {
        return response()->json($gifts->presentSent($gifts->cancel($request->user(), $code)));
    }

    public function sendGift(Request $request, GiftService $gifts)
    {
        $v = $request->validate([
            'mode' => 'required|in:fixed,random',
            'amount' => 'required|integer|min:50',
            'recipients' => 'required_if:mode,fixed|array|max:50',
            'recipients.*' => 'string|max:25',
            'shares' => 'required_if:mode,random|nullable|integer|min:1|max:100',
            'message' => 'nullable|string|max:190',
            'occasion' => 'nullable|in:anniversaire,fete,felicitations,mariage,naissance,autre',
            'expires_in_hours' => 'nullable|integer|min:1|max:72',
            'source' => 'nullable|in:wallet,mobile,card',
            'source_phone' => 'required_if:source,mobile|nullable|string|max:25',
        ]);
        $source = $v['source'] ?? 'wallet';

        // Cadeau payé par mobile money ou carte : on encaisse d'abord le montant sur
        // le wallet (collecte PEEX / 3-D Secure) ; le cadeau part automatiquement dès
        // la confirmation (Transaction::booted → GiftService::completeFunding).
        if ($source !== 'wallet') {
            $gift = collect($v)->except(['source', 'source_phone'])->all();
            $total = $v['mode'] === 'fixed' ? (int) $v['amount'] * count(array_unique($v['recipients'] ?? [])) : (int) $v['amount'];
            if ($total <= 0) {
                return response()->json(['message' => 'Ajoutez au moins un destinataire.'], 422);
            }
            $gifts->precheck($request->user(), $gift);
            $flows = app(\App\Services\Peex\PeexFlowService::class);
            $meta = ['pending_gift' => $gift, 'purpose' => 'gift'];
            $tx = $source === 'card'
                ? $flows->cardDeposit($request->user(), $total, $meta)
                : $flows->deposit($request->user(), $v['source_phone'], $total, ['meta' => $meta]);

            return response()->json(app(PaymentController::class)->txPayload($tx) + ['funding' => true], 202);
        }

        $env = $gifts->create($request->user(), $v);
        return response()->json($gifts->presentSent($env) + ['share_code' => $env->code], 201);
    }

    public function showGift(string $code)
    {
        $e = GiftEnvelope::with('sender:id,full_name')->where('code', strtoupper($code))->firstOrFail();
        return response()->json($e->only(['code', 'mode', 'shares', 'claimed_shares', 'currency', 'message', 'occasion', 'status', 'expires_at']) + ['sender' => $e->sender->full_name]);
    }

    public function claimGift(Request $request, GiftService $gifts, string $code)
    {
        $claim = $gifts->claim($request->user(), $code);
        return response()->json(['amount' => $claim->amount, 'currency' => $claim->envelope->currency, 'message' => $claim->envelope->message, 'sender' => $claim->envelope->sender->full_name]);
    }

    // ------------------------------------------------ Partage de note

    public function splits(Request $request, SplitBillService $splits)
    {
        $u = $request->user();
        return response()->json([
            'created' => BillSplit::with('shares')->where('creator_id', $u->id)->latest()->limit(30)->get()->map(fn ($s) => $splits->summary($s)),
            'to_pay' => BillSplitShare::with('split.creator:id,full_name')->where(fn ($q) => $q->where('user_id', $u->id)->orWhere('phone', $u->phone))
                ->where('status', '<>', 'self')->latest()->limit(30)->get(),
        ]);
    }

    public function createSplit(Request $request, SplitBillService $splits)
    {
        $v = $request->validate([
            'title' => 'required|string|max:120',
            'total_amount' => 'required|integer|min:100',
            'mode' => 'required|in:equal,custom',
            'include_self' => 'nullable|boolean',
            'participants' => 'required|array|min:1|max:30',
            'participants.*.phone' => 'required|string|max:25',
            'participants.*.amount' => 'nullable|integer|min:1',
        ]);
        return response()->json($splits->summary($splits->create($request->user(), $v)), 201);
    }

    public function paySplitShare(Request $request, SplitBillService $splits, BillSplitShare $share)
    {
        return response()->json($splits->pay($request->user(), $share), 201);
    }

    public function declineSplitShare(Request $request, SplitBillService $splits, BillSplitShare $share)
    {
        return response()->json($splits->decline($request->user(), $share));
    }

    public function remindSplit(Request $request, SplitBillService $splits, BillSplit $split)
    {
        abort_unless($split->creator_id === $request->user()->id, 404);
        return response()->json(['reminded' => $splits->remind($split)]);
    }

    public function cancelSplit(Request $request, BillSplit $split)
    {
        abort_unless($split->creator_id === $request->user()->id && $split->status === 'open', 422, 'Partage non annulable.');
        $split->update(['status' => 'cancelled']);
        $split->shares()->where('status', 'pending')->update(['status' => 'declined']);
        return response()->json($split);
    }

    // ------------------------------------------------ Mini-programmes

    public function miniPrograms(Request $request)
    {
        $q = MiniProgram::with('merchant:id,business_name')->where('status', 'approved')->orderBy('sort')->orderBy('name');
        if ($request->filled('category')) {
            $q->where('category', $request->input('category'));
        }
        return response()->json([
            'categories' => ['recharge' => 'Recharge', 'billetterie' => 'Billetterie', 'services_publics' => 'Services publics', 'ecommerce' => 'E-commerce', 'autre' => 'Autres'],
            'programs' => $q->get(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantCashier;
use App\Models\PaymentIntent;
use App\Models\PaymentRequest;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\Ecommerce\PaymentIntentService;
use App\Services\Merchant\CashierService;
use App\Services\Merchant\PaymentRequestService;
use App\Services\Merchant\RefundService;
use App\Services\Notifications\TransactionNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Outils marchand (§3.2) : QR dynamique, liens de paiement, sessions NFC,
 * caissiers, remboursements, rapports par point de vente / caissier / canal,
 * export du relevé, paiement en ligne (clés API).
 * Les routes d'encaissement sont ouvertes aux caissiers (rôle cashier).
 */
class MerchantToolsController extends Controller
{
    public function __construct(protected PaymentRequestService $requests)
    {
    }

    // ------------------------------------------------ Encaissement (marchand + caissier)

    public function createRequest(Request $request)
    {
        $v = $request->validate([
            'kind' => 'nullable|in:dynamic_qr,payment_link,nfc',
            'amount' => 'required|integer|min:10',
            'description' => 'nullable|string|max:190',
            'reference' => 'nullable|string|max:80',
            'outlet_id' => 'nullable|integer',
            'expires_in_hours' => 'nullable|integer|min:1|max:720',
            'customer_phone' => 'nullable|string|max:25',
        ]);
        $r = $this->requests->create($request->user(), $v['kind'] ?? 'dynamic_qr', $v['amount'], $v);

        // Lien de paiement envoyé au client (SMS + notification s'il a l'app)
        if ($r->kind === 'payment_link' && ! empty($v['customer_phone'])) {
            $flows = app(\App\Services\Peex\PeexFlowService::class);
            $u = $flows->findUserByPhone(app(\App\Services\Peex\PeexCorridors::class)->resolve($v['customer_phone'])['phone']);
            $n = app(\App\Services\Notifications\NotificationService::class);
            $text = $r->merchant->business_name . ' vous demande ' . number_format($r->amount, 0, ',', ' ') . " {$r->currency}";
            $u ? $n->toUser($u, 'payment_request', $text, $r->description, ['data' => ['payment_request' => $r->token]])
               : $n->sms($v['customer_phone'], "FlashPay: {$text}. Payer: " . url("/p/{$r->token}"));
        }
        return response()->json($this->requests->present($r->fresh(['merchant', 'outlet']), true), 201);
    }

    public function listRequests(Request $request)
    {
        [$merchant, $cashier] = $this->requests->merchantOf($request->user());
        $q = PaymentRequest::with('outlet:id,name')->where('merchant_id', $merchant->id)->latest();
        if ($cashier) {
            $q->where('created_by', $request->user()->id);
        }
        if ($request->filled('kind')) {
            $q->where('kind', $request->input('kind'));
        }
        return response()->json($q->paginate(30)->through(fn ($r) => $this->requests->present($r, true)));
    }

    /** Suivi en temps réel (polling du QR affiché en caisse). */
    public function showRequest(Request $request, string $token)
    {
        [$merchant] = $this->requests->merchantOf($request->user());
        $r = $this->requests->find($token);
        abort_unless($r->merchant_id === $merchant->id, 404);
        return response()->json($this->requests->present($r, true));
    }

    public function cancelRequest(Request $request, string $token)
    {
        [$merchant] = $this->requests->merchantOf($request->user());
        $r = $this->requests->find($token);
        abort_unless($r->merchant_id === $merchant->id, 404);
        return response()->json($this->requests->present($this->requests->cancel($r), true));
    }

    /** Encaissements du caissier connecté (journal limité à ses opérations). */
    public function cashierCollections(Request $request)
    {
        $c = $request->user()->cashier;
        abort_unless($c && $c->status === 'active', 403, 'Accès caissier révoqué.');
        $txs = Transaction::where('destination_wallet_id', $c->merchant->user->wallet->id)
            ->where('meta->cashier_id', $c->id)->latest()->paginate(30);
        return response()->json($txs->toArray() + [
            'today' => (int) Transaction::where('destination_wallet_id', $c->merchant->user->wallet->id)->where('meta->cashier_id', $c->id)
                ->where('status', 'successful')->whereDate('created_at', today())->sum('amount'),
            'today_count' => Transaction::where('destination_wallet_id', $c->merchant->user->wallet->id)->where('meta->cashier_id', $c->id)
                ->where('status', 'successful')->whereDate('created_at', today())->count(),
            'merchant' => $c->merchant->business_name, 'outlet' => $c->outlet?->name,
        ]);
    }

    // ------------------------------------------------ Caissiers (marchand principal)

    public function cashiers(Request $request)
    {
        return response()->json(MerchantCashier::with('user:id,full_name,phone', 'outlet:id,name')
            ->where('merchant_id', $request->user()->merchant->id)->latest()->get()
            ->map(fn ($c) => $c->toArray() + [
                'today_collected' => (int) Transaction::where('meta->cashier_id', $c->id)->where('status', 'successful')->whereDate('created_at', today())->sum('amount'),
                'last_login' => $c->user?->devices()->max('last_seen_at'),
            ]));
    }

    public function createCashier(Request $request, CashierService $service)
    {
        $v = $request->validate([
            'full_name' => 'required|string|max:150',
            'phone' => 'required|string|max:25',
            'password' => 'required|string|min:4', // code secret du caissier (4 chiffres ou plus)
            'outlet_id' => 'nullable|integer',
        ]);
        return response()->json($service->create($request->user()->merchant, $v), 201);
    }

    public function updateCashier(Request $request, MerchantCashier $cashier, CashierService $service)
    {
        abort_unless($cashier->merchant_id === $request->user()->merchant->id, 404);
        $v = $request->validate(['action' => 'required|in:revoke,reactivate,move', 'reason' => 'nullable|string|max:150', 'outlet_id' => 'nullable|integer']);
        $c = match ($v['action']) {
            'revoke' => $service->revoke($cashier, $v['reason'] ?? null),
            'reactivate' => $service->reactivate($cashier),
            'move' => tap($cashier)->update(['outlet_id' => $v['outlet_id'] ?? null]),
        };
        return response()->json($c->fresh('user:id,full_name,phone', 'outlet:id,name'));
    }

    // ------------------------------------------------ Remboursements (§13.2)

    public function refund(Request $request, RefundService $refunds)
    {
        $v = $request->validate([
            'transaction_id' => 'required|integer',
            'amount' => 'nullable|integer|min:1',
            'reason' => 'nullable|string|max:190',
        ]);
        $tx = Transaction::findOrFail($v['transaction_id']);
        abort_unless($tx->destination_wallet_id === $request->user()->wallet?->id, 404, 'Paiement introuvable.');
        $r = $refunds->refund($tx, $v['amount'] ?? ($tx->amount - (int) Refund::where('transaction_id', $tx->id)->sum('amount')), 'merchant', $request->user(), $v['reason'] ?? null);
        return response()->json($r->load('refundTransaction'), 201);
    }

    public function refunds(Request $request)
    {
        return response()->json(Refund::with('transaction:id,reference,amount,created_at')->where('merchant_id', $request->user()->merchant->id)->latest()->paginate(30));
    }

    // ------------------------------------------------ Rapports & export (§3.2.3, §3.2.4, §4.6.5)

    protected function collectionsQuery(Request $request)
    {
        $wid = $request->user()->wallet->id;
        $q = Transaction::where('destination_wallet_id', $wid)->whereIn('type', TransactionNotifier::MERCHANT_TYPES);
        if ($request->filled('from')) {
            $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($request->input('to'))->endOfDay());
        }
        if ($request->filled('outlet_id')) {
            $q->where('meta->outlet_id', (int) $request->input('outlet_id'));
        }
        if ($request->filled('cashier_id')) {
            $q->where('meta->cashier_id', (int) $request->input('cashier_id'));
        }
        if ($request->filled('channel')) {
            $q->where('type', $request->input('channel'));
        }
        return $q;
    }

    public function reports(Request $request)
    {
        $m = $request->user()->merchant;
        $rows = $this->collectionsQuery($request)->where('status', 'successful')->get();
        $net = fn ($g) => (int) $g->sum(fn ($t) => ($t->destination_amount ?? $t->amount) - $t->merchant_fee);
        $outlets = $m->outlets->keyBy('id');
        $cashiers = MerchantCashier::with('user:id,full_name')->where('merchant_id', $m->id)->get()->keyBy('id');
        $notifier = app(TransactionNotifier::class);

        return response()->json([
            'total' => ['count' => $rows->count(), 'gross' => (int) $rows->sum('amount'), 'fees' => (int) $rows->sum('merchant_fee'), 'net' => $net($rows)],
            'by_outlet' => $rows->groupBy(fn ($t) => $t->meta['outlet_id'] ?? 0)->map(fn ($g, $id) => ['outlet_id' => $id ?: null, 'name' => $id ? ($outlets[$id]->name ?? '#' . $id) : 'Principal', 'count' => $g->count(), 'net' => $net($g)])->values(),
            'by_cashier' => $rows->groupBy(fn ($t) => $t->meta['cashier_id'] ?? 0)->map(fn ($g, $id) => ['cashier_id' => $id ?: null, 'name' => $id ? ($cashiers[$id]->user->full_name ?? '#' . $id) : 'Marchand', 'count' => $g->count(), 'net' => $net($g)])->values(),
            'by_channel' => $rows->groupBy(fn ($t) => $notifier->channelLabel($t))->map(fn ($g, $label) => ['channel' => $label, 'count' => $g->count(), 'net' => $net($g)])->values(),
            'by_day' => $rows->groupBy(fn ($t) => $t->created_at->toDateString())->map(fn ($g, $d) => ['date' => $d, 'count' => $g->count(), 'net' => $net($g)])->sortKeys()->values(),
        ]);
    }

    /** Relevé exportable (CSV compatible Excel) pour la réconciliation comptable. */
    public function statement(Request $request)
    {
        $rows = $this->collectionsQuery($request)->orderBy('created_at')->get();
        $notifier = app(TransactionNotifier::class);
        $outlets = $request->user()->merchant->outlets->keyBy('id');

        return response()->streamDownload(function () use ($rows, $notifier, $outlets) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Référence', 'Canal', 'Point de vente', 'Caissier', 'Payeur', 'Référence commande', 'Montant', 'Commission', 'Net', 'Devise', 'Statut'], ';');
            foreach ($rows as $t) {
                fputcsv($out, [
                    $t->created_at->format('Y-m-d H:i:s'), $t->reference, $notifier->channelLabel($t),
                    isset($t->meta['outlet_id']) ? ($outlets[$t->meta['outlet_id']]->name ?? '') : 'Principal',
                    isset($t->meta['cashier_user_id']) ? (\App\Models\User::find($t->meta['cashier_user_id'])?->full_name ?? '') : '',
                    $t->meta['payer_name'] ?? '', $t->meta['order_reference'] ?? '',
                    $t->amount, $t->merchant_fee, ($t->destination_amount ?? $t->amount) - $t->merchant_fee,
                    $t->destination_currency ?: $t->currency, $t->status,
                ], ';');
            }
            fclose($out);
        }, 'releve-flashpay-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------ Paiement en ligne (§3.2.5, §4.7.1)

    public function apiKeys(Request $request)
    {
        $m = $request->user()->merchant;
        return response()->json([
            'online_payments' => (bool) $m->online_payments,
            'keys' => $m->apiKeys()->where('active', true)->get()->map(fn ($k) => $k->only(['id', 'environment', 'public_key', 'secret_last4', 'webhook_url', 'allowed_ips', 'last_used_at', 'created_at'])),
            'docs_url' => url('/docs/api-ecommerce'),
            'integration' => app(\App\Services\Ecommerce\IntegrationStatusService::class)->status($m),
            'widget_url' => url('/js/flashpay-checkout.js'),
        ]);
    }

    public function issueApiKeys(Request $request, PaymentIntentService $service)
    {
        $v = $request->validate([
            'environment' => 'required|in:sandbox,live',
            'webhook_url' => 'nullable|url|max:255',
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'ip',
        ]);
        $r = $service->issueKeys($request->user()->merchant, $v['environment'], $v);
        return response()->json([
            'public_key' => $r['key']->public_key,
            'secret_key' => $r['secret_key'],
            'webhook_secret' => $r['webhook_secret'],
            'environment' => $r['key']->environment,
            'message' => 'Conservez la clé secrète : elle ne sera plus jamais affichée.',
        ], 201);
    }

    public function updateApiKey(Request $request, \App\Models\MerchantApiKey $key)
    {
        abort_unless($key->merchant_id === $request->user()->merchant->id, 404);
        $v = $request->validate(['webhook_url' => 'nullable|url|max:255', 'allowed_ips' => 'nullable|array', 'allowed_ips.*' => 'ip', 'active' => 'nullable|boolean']);
        $key->update(array_intersect_key($v, array_flip(['webhook_url', 'allowed_ips', 'active'])));
        return response()->json($key->only(['id', 'environment', 'public_key', 'webhook_url', 'allowed_ips', 'active']));
    }

    public function intents(Request $request)
    {
        $q = PaymentIntent::where('merchant_id', $request->user()->merchant->id)->latest();
        if ($request->filled('environment')) {
            $q->where('environment', $request->input('environment'));
        }
        return response()->json($q->paginate(30));
    }
}

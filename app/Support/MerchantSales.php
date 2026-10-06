<?php

namespace App\Support;

use App\Models\MerchantCashier;
use App\Models\Refund;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ventes d'un marchand (ou d'un caissier) : filtres, totaux de la sélection et
 * ligne détaillée (client, moyen, caissier, point de vente, commission, net,
 * récapitulatif du reçu). Utilisé par l'historique marchand et caissier.
 */
class MerchantSales
{
    public const METHODS = [
        'qr_payment' => 'QR code', 'nfc_payment' => 'NFC', 'collection' => 'Demande USSD', 'manual_payment' => 'Saisie / lien',
        'ecommerce_payment' => 'Paiement en ligne', 'mini_program_payment' => 'Mini-programme', 'merchant_payment' => 'Paiement',
    ];

    public static function filter($q, Request $request)
    {
        $tz = config('app.display_timezone', 'Africa/Brazzaville');
        if ($request->filled('from')) {
            $q->where('created_at', '>=', Carbon::parse($request->input('from'), $tz)->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', Carbon::parse($request->input('to'), $tz)->endOfDay()->utc());
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('cashier_id')) {
            $q->where('meta->cashier_id', (int) $request->input('cashier_id'));
        }
        if ($request->filled('outlet_id')) {
            $q->where('meta->outlet_id', (int) $request->input('outlet_id'));
        }
        if ($term = trim((string) $request->input('q'))) {
            $digits = preg_replace('/\D/', '', $term);
            $q->where(function ($w) use ($term, $digits) {
                $w->where('reference', 'like', "%{$term}%")->orWhere('meta', 'like', "%{$term}%");
                if (strlen($digits) >= 6) {
                    $w->orWhere('source_account', 'like', "%{$digits}%")
                        ->orWhereHas('sourceWallet.user', fn ($u) => $u->where('phone', 'like', "%{$digits}%"));
                }
            });
        }
        return $q;
    }

    public static function summary($q): array
    {
        $ok = (clone $q)->where('status', 'successful')->get(['amount', 'destination_amount', 'merchant_fee']);
        return [
            'count' => $ok->count(),
            'gross' => (int) $ok->sum(fn ($t) => (int) ($t->destination_amount ?? $t->amount)),
            'commission' => (int) $ok->sum('merchant_fee'),
            'net' => (int) $ok->sum(fn ($t) => (int) ($t->destination_amount ?? $t->amount) - (int) $t->merchant_fee),
            'pending' => (clone $q)->where('status', 'processing')->count(),
        ];
    }

    /** Transforme une page de transactions en lignes de vente. */
    public static function rows($page, int $merchantId): void
    {
        $col = $page->getCollection();
        $cashierIds = $col->map(fn ($t) => $t->meta['cashier_id'] ?? null)->filter()->unique();
        $cashiers = MerchantCashier::with('user:id,full_name')->whereIn('id', $cashierIds)->get()->keyBy('id');
        $outlets = \App\Models\MerchantOutlet::where('merchant_id', $merchantId)->get()->keyBy('id');
        $refunded = Refund::whereIn('transaction_id', $col->pluck('id'))->groupBy('transaction_id')
            ->selectRaw('transaction_id, SUM(amount) as total')->pluck('total', 'transaction_id');

        $page->setCollection($col->map(function (Transaction $t) use ($cashiers, $outlets, $refunded) {
            $m = $t->meta ?? [];
            $p = TransactionPresenter::parties($t);
            $gross = (int) ($t->destination_amount ?? $t->amount);
            $method = match ($m['method'] ?? null) {
                'pay_code' => 'Code client',
                'dynamic_qr' => 'QR dynamique',
                'payment_link' => 'Lien de paiement',
                'nfc' => 'NFC',
                'ussd' => 'Demande USSD',
                default => self::METHODS[$t->type] ?? $t->typeLabel(),
            };
            $cashier = $cashiers[$m['cashier_id'] ?? 0] ?? null;

            return [
                'id' => $t->id,
                'reference' => $t->reference,
                'type' => $t->type,
                'method' => $method,
                'status' => $t->status,
                'status_label' => $t->statusLabel(),
                'failure_reason' => $t->failure_reason,
                'amount' => $gross,
                'commission' => (int) $t->merchant_fee,
                'net' => $gross - (int) $t->merchant_fee,
                'refunded' => (int) ($refunded[$t->id] ?? 0),
                'currency' => $t->destination_currency ?: $t->currency,
                'payer_name' => $m['payer_name'] ?? $p['sender_name'],
                'payer_phone' => $p['sender_phone'],
                'cashier_id' => $m['cashier_id'] ?? null,
                'cashier_name' => $cashier?->user?->full_name,
                'outlet_name' => $outlets[$m['outlet_id'] ?? 0]->name ?? null,
                'note' => $m['note'] ?? $m['description'] ?? null,
                'created_at' => $t->created_at,
                'completed_at' => $t->completed_at,
                'details' => TransactionPresenter::details($t, true),
            ] + $p;
        }));
    }
}

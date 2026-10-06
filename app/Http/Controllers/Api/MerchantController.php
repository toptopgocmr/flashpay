<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantOutlet;
use App\Models\Transaction;
use App\Services\Peex\PeexFlowService;
use App\Services\SwitchService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MerchantController extends Controller
{
    public function __construct(protected SwitchService $switchService)
    {
    }

    public function dashboard(Request $request)
    {
        $merchant = $request->user()->merchant;
        $walletId = $request->user()->wallet->id;

        // Montant net crédité au marchand (après change et commission)
        $net = 'COALESCE(destination_amount, amount) - merchant_fee';
        $today = (int) Transaction::where('destination_wallet_id', $walletId)
            ->where('status', 'successful')
            ->whereDate('created_at', today())
            ->sum(\Illuminate\Support\Facades\DB::raw($net));

        // Nombre de ventes du jour (en-tête « Ventes du jour » de l'espace marchand)
        $todayCount = Transaction::where('destination_wallet_id', $walletId)
            ->where('status', 'successful')
            ->whereDate('created_at', today())
            ->count();

        $totalCollected = (int) Transaction::where('destination_wallet_id', $walletId)
            ->where('status', 'successful')
            ->sum(\Illuminate\Support\Facades\DB::raw($net));

        $todayCommission = (int) Transaction::where('destination_wallet_id', $walletId)
            ->where('status', 'successful')->whereDate('created_at', today())->sum('merchant_fee');
        $pending = Transaction::where('destination_wallet_id', $walletId)->where('status', 'processing')->count();

        return response()->json([
            'merchant' => $merchant,
            'wallet' => $request->user()->wallet,
            'today_collected' => $today,
            'today_count' => $todayCount,
            'today_commission' => $todayCommission,
            'pending_count' => $pending,
            'total_collected' => $totalCollected,
            'cashiers_count' => \App\Models\MerchantCashier::where('merchant_id', $merchant?->id)->where('status', 'active')->count(),
            'outlets_count' => $merchant?->outlets()->count() ?? 0,
        ]);
    }

    public function outlets(Request $request)
    {
        return response()->json($request->user()->merchant->outlets);
    }

    public function createOutlet(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'address' => 'nullable|string|max:255',
        ]);

        $outlet = MerchantOutlet::create([
            'merchant_id' => $request->user()->merchant->id,
            'name' => $validated['name'],
            'address' => $validated['address'] ?? null,
            'qr_code_token' => 'FPO-' . Str::upper(Str::random(12)),
        ]);

        return response()->json($outlet, 201);
    }

    /**
     * Retrait des fonds collectés vers mobile money (tout opérateur couvert
     * par PEEX : MTN, Airtel, Orange, Moov… dans la sous-région).
     */
    public function requestWithdrawal(Request $request, PeexFlowService $flows)
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:100',
            'destination_account' => 'nullable|string|max:25',
            'destination_rail' => 'nullable|string',
            'destination_country' => 'nullable|string|size:2',
        ]);

        $merchant = $request->user()->merchant;
        $phone = $validated['destination_account'] ?? $merchant?->settlement_phone ?? $request->user()->phone;

        $transaction = $flows->withdraw($request->user(), $phone, $validated['amount'], $validated);

        return response()->json($transaction, match ($transaction->status) {
            'successful' => 201,
            'processing' => 202,
            default => 422,
        });
    }

    /**
     * Encaissement USSD (client sans application) : le marchand saisit le
     * numéro du client ; PEEX envoie une demande de validation (USSD / push)
     * sur le téléphone du client, qui confirme avec son code mobile money.
     */
    public function collectUssd(Request $request, PeexFlowService $flows)
    {
        $v = $request->validate([
            'customer_phone' => 'required|string|max:25',
            'customer_country' => 'nullable|string|size:2',
            'customer_name' => 'nullable|string|max:100',
            'amount' => 'required|integer|min:10',
            'outlet_id' => 'nullable|integer',
        ]);

        $merchant = $request->user()->merchant;
        abort_unless($merchant, 404, 'Compte marchand introuvable.');

        $transaction = $flows->payMerchant(
            $request->user(), null, $merchant, 'mobile', $v['customer_phone'], $v['amount'], 'ussd',
            ['source_country' => $v['customer_country'] ?? null, 'payer_name' => $v['customer_name'] ?? null, 'outlet_id' => $v['outlet_id'] ?? null],
        );

        return response()->json($transaction->load('peexRequests'), match ($transaction->status) {
            'successful' => 201,
            'processing' => 202,
            default => 422,
        });
    }

    /** Contenu du QR marchand (lu par l'app FlashPay). */
    public function qr(Request $request)
    {
        $merchant = $request->user()->merchant;

        return response()->json([
            'code' => $merchant->qr_code_token,
            'payload' => 'flashpay://pay?m=' . $merchant->qr_code_token,
            'name' => $merchant->business_name,
            'outlets' => $merchant->outlets->map(fn ($o) => [
                'id' => $o->id, 'name' => $o->name, 'payload' => 'flashpay://pay?m=' . $o->qr_code_token,
            ]),
        ]);
    }

    /**
     * Historique des ventes : filtres (from, to, q, status, cashier_id, outlet_id),
     * totaux de la sélection et détail de chaque vente (reçu, caissier, net).
     */
    public function collections(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date', 'q' => 'nullable|string|max:60',
            'status' => 'nullable|in:successful,processing,failed,reversed',
            'cashier_id' => 'nullable|integer', 'outlet_id' => 'nullable|integer',
        ]);
        $user = $request->user();
        $q = \App\Support\MerchantSales::filter(Transaction::where('destination_wallet_id', $user->wallet->id), $request);
        $summary = \App\Support\MerchantSales::summary($q);
        $page = $q->with('sourceWallet.user:id,full_name,phone')->latest()->paginate(20)->withQueryString();
        \App\Support\MerchantSales::rows($page, (int) $user->merchant?->id);

        return response()->json($page->toArray() + ['summary' => $summary]);
    }
}

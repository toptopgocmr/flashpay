<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Peex\PeexClient;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexException;
use App\Services\Peex\PeexFlowService;
use Illuminate\Http\Request;

/**
 * Endpoints PEEX exposés à l'app mobile (Client).
 */
class PeexController extends Controller
{
    public function __construct(
        protected PeexCorridors $corridors,
        protected PeexFlowService $flows,
    ) {
    }

    public function corridors()
    {
        return response()->json([
            'sandbox' => (bool) config('flashpay.peex.sandbox'),
            'environments' => [
                ['key' => 'peex', 'name' => 'PEEX', 'sandbox' => (bool) config('flashpay.peex.sandbox'), 'enabled' => true],
                ['key' => 'digitwace', 'name' => 'WacePay', 'sandbox' => (bool) config('flashpay.digitwace.sandbox', true), 'enabled' => app(\App\Services\Digitwace\DigitwaceClient::class)->enabled()],
            ],
            'corridors' => $this->corridors->catalog()]);
    }

    public function resolvePhone(Request $request)
    {
        $v = $request->validate(['phone' => 'required|string', 'country' => 'nullable|string|size:2']);

        try {
            return response()->json($this->corridors->resolve($v['phone'], $v['country'] ?? null));
        } catch (PeexException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function fees(Request $request, PeexClient $client)
    {
        $v = $request->validate([
            'amount' => 'required|numeric|min:1',
            'phone' => 'required|string',
            'country' => 'nullable|string|size:2',
        ]);

        try {
            $route = $this->corridors->resolve($v['phone'], $v['country'] ?? null);
            $fees = $client->collectFees([
                'amount' => $v['amount'],
                'country' => $route['country'],
                'phone_number' => $route['local'],
            ]);
            return response()->json(['route' => $route, 'peex' => $fees]);
        } catch (PeexException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Recharge du wallet FlashPay depuis un numéro MTN / Airtel (collecte PEEX). */
    public function cashInMobile(Request $request)
    {
        $v = $request->validate([
            'phone' => 'required|string',
            'amount' => 'required|integer|min:10',
            'country' => 'nullable|string|size:2',
        ]);

        $tx = $this->flows->cashIn($request->user(), $v['phone'], $v['amount'], array_filter([
            'source_country' => $v['country'] ?? null,
        ]));

        return $this->respond($tx);
    }

    /** Transfert mobile money -> mobile money (ex: MTN CG -> Airtel CG, CG -> CM...). */
    public function mobileTransfer(Request $request)
    {
        $v = $request->validate([
            'source_phone' => 'required|string',
            'destination_phone' => 'required|string',
            'amount' => 'required|integer|min:10',
            'source_country' => 'nullable|string|size:2',
            'destination_country' => 'nullable|string|size:2',
            'beneficiary_name' => 'nullable|string|max:100',
            'purpose' => 'nullable|string|max:50',
        ]);

        $tx = $this->flows->mobileTransfer($request->user(), $v['source_phone'], $v['destination_phone'], $v['amount'], array_filter([
            'source_country' => $v['source_country'] ?? null,
            'destination_country' => $v['destination_country'] ?? null,
            'beneficiary_name' => $v['beneficiary_name'] ?? null,
            'purpose' => $v['purpose'] ?? null,
        ]));

        return $this->respond($tx);
    }

    protected function respond(Transaction $tx)
    {
        $code = match ($tx->status) {
            'successful' => 201,
            'processing' => 202, // en attente de confirmation PEEX (USSD / callback)
            default => 422,
        };

        return response()->json($tx->load('peexRequests'), $code);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Services\Client\MoneyRequestService;
use Illuminate\Http\Request;

/** Demandes d'argent entre utilisateurs (app mobile v2). */
class MoneyRequestController extends Controller
{
    public function __construct(protected MoneyRequestService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->listFor($request->user()));
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'phone' => 'required|string|max:25',
            'amount' => 'required|integer|min:100|max:5000000',
            'note' => 'nullable|string|max:140',
            'channel' => 'nullable|in:app,sms,whatsapp,link',
        ]);
        $req = $this->service->create($request->user(), $v);
        return response()->json($this->service->present($req, $request->user()), 201);
    }

    public function show(Request $request, MoneyRequest $moneyRequest)
    {
        $u = $request->user();
        $phones = array_map(fn ($p) => ltrim($p, '+'), \App\Support\Phone::candidates($u->phone));
        abort_unless($moneyRequest->requester_id === $u->id || $moneyRequest->payer_id === $u->id || in_array($moneyRequest->payer_phone, $phones, true), 404);
        return response()->json($this->service->present($moneyRequest, $u));
    }

    public function pay(Request $request, MoneyRequest $moneyRequest)
    {
        return response()->json($this->service->pay($request->user(), $moneyRequest), 201);
    }

    public function decline(Request $request, MoneyRequest $moneyRequest)
    {
        return response()->json($this->service->decline($request->user(), $moneyRequest));
    }

    public function cancel(Request $request, MoneyRequest $moneyRequest)
    {
        return response()->json($this->service->cancel($request->user(), $moneyRequest));
    }

    public function remind(Request $request, MoneyRequest $moneyRequest)
    {
        return response()->json($this->service->remind($request->user(), $moneyRequest));
    }
}

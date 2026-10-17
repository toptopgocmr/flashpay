<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Peex\PeexException;
use App\Services\Remit\InternationalTransferService;
use App\Services\Remit\RemitException;
use Illuminate\Http\Request;

/**
 * Transferts internationaux (app mobile) — /api/pay/international/*
 * P2P, P2B, B2P, B2B vers wallet mobile, compte bancaire ou retrait cash,
 * via WacePay (API v2) ou TerraPay selon le pays de destination.
 */
class InternationalTransferController extends Controller
{
    public function __construct(protected InternationalTransferService $service)
    {
    }

    public function options(Request $request)
    {
        return response()->json($this->service->options($request->user()));
    }

    public function services(Request $request)
    {
        $v = $request->validate(['country' => 'required|string|size:2']);
        return $this->wrap(fn () => $this->service->services(strtoupper($v['country'])));
    }

    public function banks(Request $request)
    {
        $v = $request->validate(['country' => 'required|string|size:2', 'service' => 'nullable|array', 'service.code' => 'nullable|string|max:40', 'service.name' => 'nullable|string|max:80']);
        return $this->wrap(fn () => $this->service->banks(strtoupper($v['country']), (array) ($v['service'] ?? [])));
    }

    public function cashPoints(Request $request)
    {
        $v = $request->validate(['country' => 'required|string|size:2', 'service' => 'nullable|array', 'service.code' => 'nullable|string|max:40', 'service.name' => 'nullable|string|max:80']);
        return $this->wrap(fn () => ['points' => $this->service->cashPoints(strtoupper($v['country']), (array) ($v['service'] ?? []))]);
    }

    public function lists(Request $request)
    {
        $v = $request->validate(['business_type' => 'required|in:p2p,p2b,b2p,b2b', 'country' => 'nullable|string|size:2']);
        return response()->json($this->service->lists($v['business_type'], isset($v['country']) ? strtoupper($v['country']) : null));
    }

    public function quote(Request $request)
    {
        $v = $this->validateBase($request);
        return $this->wrap(fn () => $this->service->quote($request->user(), $v));
    }

    public function send(Request $request, PaymentController $payments)
    {
        $v = $this->validateBase($request) + $request->validate([
            'service' => 'nullable|array',
            'service.code' => 'nullable|string|max:40',
            'service.name' => 'nullable|string|max:80',
            'sender' => 'nullable|array',
            'sender.first_name' => 'nullable|string|max:60', 'sender.last_name' => 'nullable|string|max:60',
            'sender.business_name' => 'nullable|string|max:120', 'sender.business_type' => 'nullable|string|max:20',
            'sender.registration_number' => 'nullable|string|max:60', 'sender.registered_at' => 'nullable|date',
            'sender.id_type' => 'nullable|in:CNI,PP,PC,CS', 'sender.id_number' => 'nullable|string|max:40',
            'sender.id_expiry' => 'nullable|date', 'sender.date_of_birth' => 'nullable|date|before:today',
            'sender.gender' => 'nullable|in:M,F', 'sender.nationality' => 'nullable|string|size:2',
            'sender.address' => 'nullable|string|max:150', 'sender.city' => 'nullable|string|max:80',
            'sender.occupation' => 'nullable|string|max:80', 'sender.email' => 'nullable|email|max:120',
            'beneficiary' => 'required|array',
            'beneficiary.first_name' => 'nullable|string|max:60', 'beneficiary.last_name' => 'nullable|string|max:60',
            'beneficiary.business_name' => 'nullable|string|max:120', 'beneficiary.business_type' => 'nullable|string|max:20',
            'beneficiary.registration_number' => 'nullable|string|max:60',
            'beneficiary.phone' => 'nullable|string|max:25', 'beneficiary.email' => 'nullable|email|max:120',
            'beneficiary.address' => 'nullable|string|max:150', 'beneficiary.city' => 'nullable|string|max:80',
            'beneficiary.id_type' => 'nullable|in:CNI,PP,PC,CS', 'beneficiary.id_number' => 'nullable|string|max:40',
            'beneficiary.date_of_birth' => 'nullable|date|before:today', 'beneficiary.id_expiry' => 'nullable|date',
            'account' => 'required|array',
            'account.msisdn' => 'nullable|string|max:25', 'account.provider' => 'nullable|string|max:20',
            'account.account' => 'nullable|string|max:40', 'account.bank_id' => 'nullable|integer',
            'account.bank_name' => 'nullable|string|max:100', 'account.swift' => 'nullable|string|max:11',
            'account.branch' => 'nullable|string|max:100', 'account.account_type' => 'nullable|in:Checking,Savings',
            'account.city' => 'nullable|string|max:80', 'account.collection_code' => 'nullable|string|max:40',
            'account.question' => 'nullable|string|max:120', 'account.answer' => 'nullable|string|max:60',
            'why' => 'nullable|array',
            'why.reason' => 'nullable|string|max:80', 'why.origin_fund' => 'nullable|string|max:80', 'why.relation' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:140',
        ]);

        try {
            $tx = $this->service->send($request->user(), $v);
        } catch (RemitException $e) {
            throw new PeexException($e->getMessage());
        }
        $code = match ($tx->status) { 'successful' => 201, 'processing' => 202, default => 422 };
        return response()->json($payments->txPayload($tx), $code);
    }

    protected function validateBase(Request $request): array
    {
        return $request->validate([
            'amount' => 'required|integer|min:1',
            'country' => 'required|string|size:2',
            'method' => 'required|in:wallet,bank,cash',
            'business_type' => 'required|in:p2p,p2b,b2p,b2b',
            // Compte à débiter : wallet (défaut), mobile money, carte liée
            'source' => 'nullable|array',
            'source.type' => 'nullable|in:wallet,mobile,card',
            'source.phone' => 'nullable|string|max:25',
            'source.linked_account_id' => 'nullable|integer',
        ]);
    }

    protected function wrap(callable $fn)
    {
        try {
            return response()->json($fn());
        } catch (RemitException $e) {
            return response()->json(['message' => preg_replace('/^(WacePay|TerraPay)\s*(\(\w+\))?\s*:\s*/', '', $e->getMessage())], 422);
        }
    }
}

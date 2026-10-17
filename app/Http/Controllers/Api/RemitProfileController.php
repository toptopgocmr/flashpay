<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Remit\RemitProfileService;
use Illuminate\Http\Request;

/**
 * « Activer l'international » (app mobile) — /api/pay/international/profile
 * KYC de l'expéditeur collecté une fois : identité, contact, pièce, adresse (+ GPS).
 * Enregistrement par étape possible (champs facultatifs à chaque appel).
 */
class RemitProfileController extends Controller
{
    public function __construct(protected RemitProfileService $profiles)
    {
    }

    public function show(Request $request)
    {
        $v = $request->validate(['country' => 'nullable|string|size:2']);
        return response()->json($this->profiles->status($request->user(), $v['country'] ?? null) + [
            'id_types' => \App\Services\Remit\InternationalTransferService::ID_TYPES,
            'civilities' => \App\Services\Remit\RemitProfileService::CIVILITIES,
            'postal_code_countries' => array_values((array) config('flashpay.remit.postal_code_countries', [])),
            'require_email_verified' => (bool) config('flashpay.remit.require_email_verified'),
        ]);
    }

    public function update(Request $request)
    {
        $v = $request->validate([
            'first_name' => 'sometimes|required|string|max:60',
            'last_name' => 'sometimes|required|string|max:60',
            'gender' => 'sometimes|required|in:M,F,m,f',
            'date_of_birth' => 'sometimes|required|date|before_or_equal:' . now()->subYears(18)->toDateString(),
            'place_of_birth' => 'sometimes|nullable|string|max:100',
            'nationality' => 'sometimes|required|string|size:2',
            'occupation' => 'sometimes|nullable|string|max:80',
            'civility' => 'sometimes|required|in:Single,Married,Others',
            'pep' => 'sometimes|required|boolean',
            'email' => 'sometimes|nullable|email|max:150',
            'id_type' => 'sometimes|required|in:CNI,PP,PC,CS',
            'id_number' => 'sometimes|required|string|max:40',
            'id_issuer_country' => 'sometimes|nullable|string|size:2',
            'id_issue_date' => 'sometimes|nullable|date|before_or_equal:today',
            'id_expiry' => 'sometimes|required|date|after:today',
            'country' => 'sometimes|required|string|size:2',
            'city' => 'sometimes|required|string|max:80',
            'district' => 'sometimes|nullable|string|max:100',
            'street' => 'sometimes|nullable|string|max:150',
            'postal_code' => 'sometimes|nullable|string|max:20',
            'gps_lat' => 'sometimes|nullable|numeric|between:-90,90',
            'gps_lng' => 'sometimes|nullable|numeric|between:-180,180',
        ], [
            'date_of_birth.before_or_equal' => 'Il faut avoir 18 ans pour envoyer de l\'argent à l\'international.',
            'id_expiry.after' => 'Cette pièce d\'identité est expirée : enregistrez une pièce en cours de validité.',
        ]);
        $this->profiles->save($request->user(), $v);
        return response()->json(['message' => 'Profil international enregistré.'] + $this->profiles->status($request->user()));
    }

    public function sendEmailCode(Request $request)
    {
        return response()->json(['message' => 'Code envoyé par e-mail.'] + $this->profiles->sendEmailCode($request->user()));
    }

    public function verifyEmail(Request $request)
    {
        $v = $request->validate(['code' => 'required|string|size:6']);
        $this->profiles->verifyEmail($request->user(), $v['code']);
        return response()->json(['message' => 'E-mail vérifié.'] + $this->profiles->status($request->user()));
    }
}

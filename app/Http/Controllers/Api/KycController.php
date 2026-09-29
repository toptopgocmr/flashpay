<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Compliance\KycService;
use App\Services\Compliance\LimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Parcours KYC (§3.3.1, §12) : envoi des pièces par l'utilisateur, validation par le back-office. */
class KycController extends Controller
{
    public function __construct(protected KycService $kyc)
    {
    }

    public function show(Request $request, LimitService $limits)
    {
        $u = $request->user();
        return response()->json([
            'kyc_status' => $u->kyc_status,
            'kyc_tier' => (int) $u->kyc_tier,
            // has_file = false : le fichier a été perdu (ancien envoi, avant la copie en base) -> à renvoyer
            'documents' => $u->kycDocuments()->latest()->get()->map(fn ($d) => $d->only(['id', 'type', 'status', 'rejection_reason', 'created_at', 'reviewed_at']) + ['has_file' => $d->hasFile()]),
            'has_photo' => self::latestPhoto($u) !== null,
            'types' => KycService::TYPES,
            'limits' => $limits->summary($u),
        ]);
    }

    public function upload(Request $request)
    {
        $v = $request->validate([
            'type' => 'required|in:' . implode(',', array_keys(KycService::TYPES)),
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:6144',
            'id_number' => 'nullable|string|max:60',
        ]);
        $doc = $this->kyc->submit($request->user(), $v['type'], $request->file('file'), $v['id_number'] ?? null);
        return response()->json(['document' => $doc->only(['id', 'type', 'status', 'created_at']), 'message' => 'Document envoyé. Vérification sous 48 h.'], 201);
    }

    // ---------------------------------------------------------- Admin

    public function queue(Request $request)
    {
        $q = KycDocument::with('user:id,full_name,phone,kyc_status,kyc_tier', 'user.roles:id,name', 'reviewer:id,full_name')
            ->where('status', $request->input('status', 'pending'))->latest();
        if ($request->filled('role')) {
            $q->whereHas('user.roles', fn ($r) => $r->where('name', $request->input('role')));
        }
        return response()->json($q->paginate(30)->through(fn ($d) => $d->toArray() + [
            'role' => $d->user?->roles->pluck('name')->first(),
            'type_label' => KycService::TYPES[$d->type] ?? $d->type,
        ]));
    }

    public function userDocuments(User $user)
    {
        return response()->json($user->kycDocuments()->latest()->get()->map(fn ($d) => $d->toArray() + ['type_label' => KycService::TYPES[$d->type] ?? $d->type, 'has_file' => $d->hasFile()]));
    }

    public function file(KycDocument $document)
    {
        abort_unless($document->hasFile(), 404, 'Fichier introuvable. Merci de demander au client de renvoyer la pièce.');
        \App\Support\Audit::log('kyc.view_document', $document);
        return $document->fileResponse();
    }

    /** Console : le fichier est perdu -> on demande au client de renvoyer la pièce. */
    public function requestResend(Request $request, KycDocument $document)
    {
        $label = KycService::TYPES[$document->type] ?? $document->type;
        $document->update(['status' => 'rejected', 'rejection_reason' => 'Fichier illisible ou perdu : merci de renvoyer la pièce', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        app(\App\Services\Notifications\NotificationService::class)->toUser($document->user, 'kyc_rejected', 'Merci de renvoyer une pièce', "{$label} : merci de la renvoyer depuis Profil › Identité & plafonds.", ['severity' => 'warning']);
        \App\Support\Audit::log('kyc.request_resend', $document);
        return response()->json(['message' => "Demande envoyée au client : {$label} à renvoyer."]);
    }

    /** Photo de profil d'un utilisateur (console). */
    public function userPhoto(User $user)
    {
        $doc = self::latestPhoto($user);
        abort_unless($doc, 404);
        return $doc->fileResponse();
    }

    // ---------------------------------------------------------- Propriétaire

    /** L'utilisateur consulte une de SES pièces (aperçu dans l'app). */
    public function myFile(Request $request, KycDocument $document)
    {
        abort_unless($document->user_id === $request->user()->id, 403);
        abort_unless($document->hasFile(), 404, 'Fichier introuvable. Merci de renvoyer la pièce.');
        return $document->fileResponse();
    }

    /** Photo de profil de l'utilisateur connecté (avatar dans l'app). */
    public function myPhoto(Request $request)
    {
        $doc = self::latestPhoto($request->user());
        abort_unless($doc, 404);
        return $doc->fileResponse();
    }

    public static function latestPhoto(User $user): ?KycDocument
    {
        return $user->kycDocuments()->where('type', 'profile_photo')
            ->where(fn ($q) => $q->where('status', '!=', 'rejected')->orWhere('rejection_reason', 'Remplacé par un nouvel envoi'))
            ->latest('id')->get()->first(fn ($d) => $d->hasFile());
    }

    public function review(Request $request, KycDocument $document)
    {
        $v = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required_if:decision,rejected|nullable|string|max:200']);
        $doc = $this->kyc->review($document, $v['decision'], $v['reason'] ?? null, $request->user());
        return response()->json(['document' => $doc, 'user' => $doc->user->only(['id', 'kyc_status', 'kyc_tier'])]);
    }
}

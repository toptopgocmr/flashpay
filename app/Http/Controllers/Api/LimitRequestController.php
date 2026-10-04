<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LimitRequest;
use App\Models\User;
use App\Services\Compliance\LimitService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;

/**
 * Demande de relèvement des plafonds (§12) : le client indique les plafonds
 * souhaités, le motif, une justification écrite et un justificatif (photo /
 * PDF). La conformité accepte (plafonds personnalisés, éventuellement
 * temporaires) ou refuse avec un motif ; le client est notifié.
 */
class LimitRequestController extends Controller
{
    public const FILE_MAX_KB = 6144;

    public function __construct(protected LimitService $limits, protected NotificationService $notify)
    {
    }

    // ------------------------------------------------------------ Client

    public function mine(Request $request)
    {
        $u = $request->user();
        return response()->json([
            'data' => LimitRequest::where('user_id', $u->id)->latest()->limit(20)->get()->map->present(),
            'reasons' => LimitRequest::REASONS,
            'limits' => $this->limits->summary($u),
        ]);
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $v = $request->validate([
            'per_operation' => 'nullable|integer|min:1000',
            'daily' => 'nullable|integer|min:1000',
            'monthly' => 'nullable|integer|min:1000',
            'max_balance' => 'nullable|integer|min:1000',
            'reason_type' => 'required|in:' . implode(',', array_keys(LimitRequest::REASONS)),
            'justification' => 'required|string|min:20|max:2000',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:' . self::FILE_MAX_KB,
        ], [
            'justification.min' => 'Expliquez votre besoin en quelques phrases (20 caractères minimum).',
            'file.max' => 'Justificatif trop lourd : 6 Mo maximum.',
        ]);
        if (! array_filter([$v['per_operation'] ?? null, $v['daily'] ?? null, $v['monthly'] ?? null, $v['max_balance'] ?? null])) {
            return response()->json(['message' => 'Indiquez au moins un plafond souhaité.'], 422);
        }
        if (LimitRequest::where('user_id', $u->id)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'Vous avez déjà une demande en cours d\'examen. Attendez la réponse ou annulez-la.'], 422);
        }
        $current = $this->limits->limitsFor($u) ?? [];
        // Les plafonds demandés doivent dépasser les plafonds actuels.
        foreach (['per_operation', 'daily', 'monthly', 'max_balance'] as $k) {
            if (! empty($v[$k]) && ! empty($current[$k]) && (int) $v[$k] <= (int) $current[$k]) {
                return response()->json(['message' => 'Les plafonds demandés doivent être supérieurs à vos plafonds actuels.', 'errors' => [$k => ['Doit dépasser ' . number_format((int) $current[$k], 0, ',', ' ')]]], 422);
            }
        }

        $file = $request->file('file');
        $r = LimitRequest::create([
            'user_id' => $u->id,
            'current_tier' => (int) $u->kyc_tier,
            'current_limits' => array_intersect_key($current, array_flip(['per_operation', 'daily', 'monthly', 'max_balance'])),
            'per_operation' => $v['per_operation'] ?? null,
            'daily' => $v['daily'] ?? null,
            'monthly' => $v['monthly'] ?? null,
            'max_balance' => $v['max_balance'] ?? null,
            'currency' => $u->wallet?->currency ?? 'XAF',
            'reason_type' => $v['reason_type'],
            'justification' => trim($v['justification']),
            'file_mime' => $file ? ($file->getMimeType() ?: $file->getClientMimeType()) : null,
            'file_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 120) : null,
            'file_content' => $file ? base64_encode(file_get_contents($file->getRealPath())) : null,
        ]);

        $this->notify->toAdmins('limit_request', 'Demande de relèvement de plafonds', "{$u->full_name} ({$u->phone}) · " . (LimitRequest::REASONS[$r->reason_type] ?? ''), ['severity' => 'warning', 'data' => ['limit_request_id' => $r->id, 'user_id' => $u->id]]);

        return response()->json(['request' => $r->present(), 'message' => 'Demande envoyée. Réponse sous 48 h ouvrées.'], 201);
    }

    public function cancel(Request $request, LimitRequest $limitRequest)
    {
        abort_unless($limitRequest->user_id === $request->user()->id, 404);
        abort_unless($limitRequest->status === 'pending', 422, 'Cette demande a déjà été traitée.');
        $limitRequest->update(['status' => 'cancelled']);
        return response()->json($limitRequest->present());
    }

    public function myFile(Request $request, LimitRequest $limitRequest)
    {
        abort_unless($limitRequest->user_id === $request->user()->id, 404);
        return $limitRequest->fileResponse();
    }

    // ------------------------------------------------------------ Console

    public function index(Request $request)
    {
        $q = LimitRequest::with('user:id,full_name,phone,kyc_tier,kyc_status,custom_limits,custom_limits_until', 'reviewer:id,full_name')
            ->where('status', $request->input('status', 'pending'))->latest();
        if ($s = trim((string) $request->input('q'))) {
            $q->whereHas('user', fn ($u) => $u->where('full_name', 'like', "%{$s}%")->orWhere('phone', 'like', '%' . preg_replace('/\D/', '', $s) . '%'));
        }
        return response()->json($q->paginate(30)->through(fn ($r) => $r->present() + [
            'usage' => $r->user?->wallet ? $this->limits->usage($r->user->wallet) : null,
            'balance' => (int) ($r->user?->wallet?->balance ?? 0),
        ]));
    }

    public function file(LimitRequest $limitRequest)
    {
        \App\Support\Audit::log('limits.view_justification', $limitRequest);
        return $limitRequest->fileResponse();
    }

    public function review(Request $request, LimitRequest $limitRequest)
    {
        abort_unless($limitRequest->status === 'pending', 422, 'Cette demande a déjà été traitée.');
        $v = $request->validate([
            'decision' => 'required|in:approve,reject',
            'per_operation' => 'nullable|integer|min:0',
            'daily' => 'nullable|integer|min:0',
            'monthly' => 'nullable|integer|min:0',
            'max_balance' => 'nullable|integer|min:0',
            'until' => 'nullable|date|after:today',
            'note' => 'nullable|string|max:255|required_if:decision,reject',
        ], ['note.required_if' => 'Indiquez le motif du refus.']);
        $user = $limitRequest->user;
        $cur = $limitRequest->currency;
        $fmt = fn ($n) => number_format((int) $n, 0, ',', ' ') . " {$cur}";

        if ($v['decision'] === 'reject') {
            $limitRequest->update(['status' => 'rejected', 'decision_note' => $v['note'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $this->notify->toUser($user, 'limit_request_rejected', 'Demande de plafonds refusée', $v['note'], ['severity' => 'warning']);
            \App\Support\Audit::log('limits.request_rejected', $limitRequest);
            return response()->json($limitRequest->fresh()->present());
        }

        $granted = array_filter([
            'per_operation' => $v['per_operation'] ?? $limitRequest->per_operation,
            'daily' => $v['daily'] ?? $limitRequest->daily,
            'monthly' => $v['monthly'] ?? $limitRequest->monthly,
            'max_balance' => $v['max_balance'] ?? $limitRequest->max_balance,
        ], fn ($x) => $x !== null && (int) $x > 0);
        $granted = array_map('intval', $granted);
        if (! $granted) {
            return response()->json(['message' => 'Indiquez au moins un plafond accordé.'], 422);
        }
        // Les plafonds déjà personnalisés et non modifiés sont conservés.
        $merged = array_merge(is_array($user->custom_limits) ? $user->custom_limits : [], $granted);
        $user->forceFill(['custom_limits' => $merged, 'custom_limits_until' => $v['until'] ?? null])->save();
        $limitRequest->update(['status' => 'approved', 'granted' => $granted, 'granted_until' => $v['until'] ?? null, 'decision_note' => $v['note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        $labels = ['per_operation' => 'par opération', 'daily' => 'par jour', 'monthly' => 'par mois', 'max_balance' => 'solde max'];
        $txt = collect($granted)->map(fn ($n, $k) => $labels[$k] . ' ' . $fmt($n))->implode(' · ');
        $this->notify->toUser($user, 'limit_request_approved', 'Plafonds relevés ✅', $txt . (! empty($v['until']) ? ' (jusqu\'au ' . \Illuminate\Support\Carbon::parse($v['until'])->format('d/m/Y') . ')' : '') . ($v['note'] ?? '' ? '. ' . $v['note'] : ''), ['severity' => 'success']);
        \App\Support\Audit::log('limits.request_approved', $limitRequest, ['granted' => $granted]);

        return response()->json($limitRequest->fresh()->present());
    }

    /** Retirer les plafonds personnalisés d'un utilisateur (retour au palier KYC). */
    public function resetCustom(Request $request, User $user)
    {
        $user->forceFill(['custom_limits' => null, 'custom_limits_until' => null])->save();
        \App\Support\Audit::log('limits.custom_reset', $user);
        return response()->json(['message' => 'Plafonds personnalisés retirés : retour aux plafonds du palier KYC.']);
    }
}

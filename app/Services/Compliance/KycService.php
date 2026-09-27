<?php

namespace App\Services\Compliance;

use App\Exceptions\BusinessException;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;

/**
 * KYC proportionné (§3.1.1, §3.2.1, §3.3.1, §12) :
 *   Palier 0 : téléphone + OTP (inscription)
 *   Palier 1 : pièce d'identité validée
 *   Palier 2 : pièce d'identité + selfie validés (KYC complet, international)
 * Agents : pièce + justificatif de point de vente ; marchands : registre de commerce + pièce du gérant.
 */
class KycService
{
    public const TYPES = [
        'id_card' => 'Carte nationale d\'identité',
        'id_card_back' => 'Pièce d\'identité (verso)',
        'profile_photo' => 'Photo de profil / du gérant',
        'shop_photo' => 'Photo de la boutique (façade ou point de vente)',
        'passport' => 'Passeport',
        'selfie' => 'Selfie avec la pièce',
        'proof_of_address' => 'Justificatif de domicile',
        'trade_register' => 'Registre de commerce (RCCM) ou équivalent',
        'pos_proof' => 'Justificatif de point de vente',
    ];

    public function __construct(protected NotificationService $notify)
    {
    }

    public function submit(User $user, string $type, UploadedFile $file, ?string $idNumber = null): KycDocument
    {
        if (! isset(self::TYPES[$type])) {
            throw new BusinessException('Type de document inconnu.', 'kyc_type');
        }
        // Stockage privé (jamais exposé publiquement), chiffré par le disque en production
        $path = $file->store("kyc/{$user->id}", 'local');

        KycDocument::where('user_id', $user->id)->where('type', $type)->where('status', 'pending')->update(['status' => 'rejected', 'rejection_reason' => 'Remplacé par un nouvel envoi']);
        $doc = KycDocument::create(['user_id' => $user->id, 'type' => $type, 'path' => $path, 'status' => 'pending']);

        $user->update(array_filter(['kyc_status' => $user->kyc_status === 'verified' ? 'verified' : 'submitted', 'id_number' => $idNumber]));
        $role = $user->getRoleNames()->first() ?? 'client';
        $this->notify->toAdmins('kyc_pending', 'Nouvelle demande KYC (' . $role . ')', "{$user->full_name} — " . self::TYPES[$type], ['data' => ['user_id' => $user->id, 'document_id' => $doc->id]]);

        return $doc;
    }

    public function review(KycDocument $doc, string $decision, ?string $reason, User $admin): KycDocument
    {
        $doc->update(['status' => $decision, 'rejection_reason' => $decision === 'rejected' ? $reason : null, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        $user = $doc->user;
        $before = (int) $user->kyc_tier;
        $tier = $this->computeTier($user);

        $pendingLeft = KycDocument::where('user_id', $user->id)->where('status', 'pending')->exists();
        $status = $tier >= 2 ? 'verified' : ($decision === 'rejected' && ! $pendingLeft && $tier === 0 ? 'rejected' : ($pendingLeft ? 'submitted' : ($tier > 0 ? 'verified' : $user->kyc_status)));
        if (! $user->hasRole('client') && $decision === 'approved' && ! $pendingLeft) {
            $status = 'verified';
        }
        $user->update(['kyc_tier' => max($before, $tier), 'kyc_status' => $status]);

        Audit::log('kyc.review', $doc, ['decision' => $decision, 'reason' => $reason, 'tier' => $tier], $admin->id);

        if ($decision === 'rejected') {
            $this->notify->toUser($user, 'kyc_rejected', 'Document KYC refusé', (self::TYPES[$doc->type] ?? $doc->type) . ' : ' . ($reason ?: 'document illisible ou non conforme') . '. Merci de renvoyer un document.', ['severity' => 'warning']);
        }
        $this->notifyTierChange($user, $before, max($before, $tier));

        return $doc->fresh();
    }

    public function computeTier(User $user): int
    {
        $approved = KycDocument::where('user_id', $user->id)->where('status', 'approved')->pluck('type')->all();
        $identity = (bool) array_intersect(['id_card', 'passport'], $approved);
        // Palier 2 : pièce + SELFIE avec la pièce (obligatoire — la photo de
        // profil de l'inscription ne suffit pas) + verso de la CNI s'il a été envoyé.
        $face = in_array('selfie', $approved, true);
        $backPending = ! in_array('id_card_back', $approved, true)
            && KycDocument::where('user_id', $user->id)->where('type', 'id_card_back')->exists();
        if ($identity && $face && ! $backPending) {
            return 2;
        }
        return $identity ? 1 : 0;
    }

    /** Notification « Levée de plafond » (§11.1). */
    public function notifyTierChange(User $user, int $before, int $after): void
    {
        if ($after > $before) {
            $l = config("limits.client.{$after}");
            $this->notify->toUser($user, 'limit_raised', 'Vos plafonds ont été relevés', ($l['label'] ?? "Palier {$after}") . ' : jusqu\'à ' . number_format($l['daily'] ?? 0, 0, ',', ' ') . ' par jour.', ['severity' => 'success']);
        }
    }
}

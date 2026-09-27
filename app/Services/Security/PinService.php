<?php

namespace App\Services\Security;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Hash;

/**
 * PIN applicatif (§4.5) : 4 à 6 chiffres, verrouillage après N erreurs.
 * La biométrie de l'app déverrouille le PIN stocké dans le coffre sécurisé
 * du téléphone : côté serveur, seule la vérification du PIN fait foi.
 */
class PinService
{
    public function __construct(protected NotificationService $notify)
    {
    }

    public function set(User $user, string $pin): void
    {
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            throw new BusinessException('Le PIN doit contenir 4 à 6 chiffres.', 'pin_format');
        }
        if (preg_match('/^(\d)\1+$/', $pin) || in_array($pin, ['1234', '12345', '123456', '0000'], true)) {
            throw new BusinessException('PIN trop simple. Choisissez un autre code.', 'pin_weak');
        }
        $first = ! $user->hasPin();
        $user->forceFill(['pin_hash' => Hash::make($pin), 'pin_attempts' => 0, 'pin_locked_until' => null, 'pin_changed_at' => now()])->save();

        if (! $first) {
            $this->notify->toUser($user, 'pin_changed', 'Votre code PIN a été modifié', 'Si vous n\'êtes pas à l\'origine de ce changement, contactez immédiatement le support.', ['severity' => 'warning']);
        }
    }

    /** Vérifie le PIN (lève une exception explicite en cas d'échec / verrouillage). */
    public function check(User $user, ?string $pin): void
    {
        if (! $user->hasPin()) {
            if (config('security.pin_mandatory')) {
                throw new BusinessException('Définissez votre code PIN pour valider vos opérations.', 'pin_not_set', 428);
            }
            return;
        }
        if ($user->pin_locked_until && $user->pin_locked_until->isFuture()) {
            throw new BusinessException('PIN verrouillé après trop d\'erreurs. Réessayez après ' . $user->pin_locked_until->format('H:i') . ' ou réinitialisez votre PIN.', 'pin_locked', 423);
        }
        if (! $pin) {
            throw new BusinessException('Code PIN requis pour confirmer l\'opération.', 'pin_required', 428);
        }
        if (! Hash::check($pin, $user->pin_hash)) {
            $attempts = $user->pin_attempts + 1;
            $max = config('security.pin_max_attempts', 5);
            $locked = $attempts >= $max;
            $user->forceFill([
                'pin_attempts' => $locked ? 0 : $attempts,
                'pin_locked_until' => $locked ? now()->addMinutes(config('security.pin_lock_minutes', 30)) : null,
            ])->save();
            if ($locked) {
                $this->notify->toUser($user, 'account_blocked', 'PIN verrouillé', 'Trop de tentatives erronées. Vos opérations sont suspendues ' . config('security.pin_lock_minutes', 30) . ' minutes.', ['severity' => 'critical']);
                throw new BusinessException('PIN verrouillé après trop d\'erreurs.', 'pin_locked', 423);
            }
            throw new BusinessException('Code PIN incorrect (' . ($max - $attempts) . ' essai(s) restant(s)).', 'pin_invalid', 422, ['remaining' => $max - $attempts]);
        }
        if ($user->pin_attempts > 0) {
            $user->forceFill(['pin_attempts' => 0])->save();
        }
    }
}

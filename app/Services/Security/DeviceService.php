<?php

namespace App\Services\Security;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notifications\NotificationService;

/** Appareils et sessions (§15) : un jeton Sanctum par appareil. */
class DeviceService
{
    public function __construct(protected NotificationService $notify)
    {
    }

    public function isKnown(User $user, ?string $deviceId): bool
    {
        if (! $deviceId) {
            return false;
        }
        return UserDevice::where('user_id', $user->id)->where('device_id', $deviceId)->whereNull('revoked_at')->exists();
    }

    public function hasAnyDevice(User $user): bool
    {
        return UserDevice::where('user_id', $user->id)->exists();
    }

    /** Enregistre la connexion et crée le jeton de l'appareil. */
    public function login(User $user, array $d): string
    {
        $deviceId = $d['device_id'] ?? 'unknown';
        $known = $this->isKnown($user, $deviceId);
        $hadDevices = $this->hasAnyDevice($user);

        if (config('security.device_policy') === 'single') {
            $user->tokens()->delete();
            UserDevice::where('user_id', $user->id)->where('device_id', '!=', $deviceId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        UserDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_id' => $deviceId],
            array_filter([
                'name' => $d['device_name'] ?? null,
                'platform' => $d['platform'] ?? null,
                'app_version' => $d['app_version'] ?? null,
                'push_token' => $d['push_token'] ?? null,
                'nfc_hce' => isset($d['nfc_hce']) ? (bool) $d['nfc_hce'] : null,
            ], fn ($v) => $v !== null) + ['last_seen_at' => now(), 'revoked_at' => null],
        );

        if (! $known && $hadDevices) {
            $this->notify->toUser($user, 'new_device', 'Nouvelle connexion à votre compte', 'Depuis ' . ($d['device_name'] ?? 'un nouvel appareil') . ' le ' . now()->format('d/m/Y H:i') . '. Si ce n\'est pas vous, bloquez votre compte depuis le support.', ['severity' => 'warning']);
        }

        return $user->createToken('device:' . $deviceId)->plainTextToken;
    }

    /** Révocation immédiate d'un appareil (perte, vol, départ d'un caissier). */
    public function revoke(User $user, UserDevice $device): void
    {
        $device->update(['revoked_at' => now()]);
        $user->tokens()->where('name', 'device:' . $device->device_id)->delete();
    }

    public function revokeAll(User $user): void
    {
        UserDevice::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $user->tokens()->delete();
    }
}

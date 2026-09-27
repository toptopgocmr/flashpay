<?php

namespace App\Services\Ops;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Paramètres modifiables à chaud depuis la console :
 *  - "channels" : mode dégradé par canal (§14) — un canal désactivé refuse
 *    les nouvelles opérations avec un message explicite affiché dans l'app ;
 *  - "limits"   : surcharge des plafonds par palier (§12).
 */
class PlatformSettings
{
    public const CHANNELS = [
        'peex_collect' => 'Recharges et paiements depuis mobile money (PEEX)',
        'peex_payout' => 'Envois et retraits vers mobile money (PEEX)',
        'card' => 'Paiement par carte',
        'bank' => 'Virements bancaires',
        'agents' => 'Réseau d\'agents (dépôts / retraits cash)',
        'ecommerce' => 'API e-commerce',
    ];

    public function get(string $key, $default = null)
    {
        return Cache::remember("platform_setting:{$key}", 30, fn () => PlatformSetting::find($key)?->value) ?? $default;
    }

    public function set(string $key, $value, ?int $userId = null): void
    {
        PlatformSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        Cache::forget("platform_setting:{$key}");
    }

    /** @return array<string, array{label:string, enabled:bool, message:?string}> */
    public function channels(): array
    {
        $saved = $this->get('channels', []);
        $out = [];
        foreach (self::CHANNELS as $k => $label) {
            $out[$k] = ['label' => $label, 'enabled' => (bool) ($saved[$k]['enabled'] ?? true), 'message' => $saved[$k]['message'] ?? null];
        }
        return $out;
    }

    public function channelEnabled(string $channel): bool
    {
        return $this->channels()[$channel]['enabled'] ?? true;
    }

    public function channelMessage(string $channel): string
    {
        $c = $this->channels()[$channel] ?? null;
        return $c['message'] ?: (($c['label'] ?? 'Ce service') . ' est temporairement indisponible (incident partenaire). Réessayez plus tard ; aucun montant n\'a été débité.');
    }
}

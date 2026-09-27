<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Push mobile (FCM) vers les appareils actifs de l'utilisateur. */
class PushSender
{
    public function send(AppNotification $n): bool
    {
        if (! $n->user_id) {
            return false;
        }
        $tokens = UserDevice::where('user_id', $n->user_id)->whereNull('revoked_at')->whereNotNull('push_token')->pluck('push_token')->all();
        if (! $tokens) {
            return false;
        }

        if (config('notifications.push_driver') === 'fcm' && config('notifications.fcm.server_key')) {
            try {
                Http::timeout(8)->withHeaders(['Authorization' => 'key=' . config('notifications.fcm.server_key')])
                    ->post('https://fcm.googleapis.com/fcm/send', [
                        'registration_ids' => $tokens,
                        'notification' => ['title' => $n->title, 'body' => $n->body, 'sound' => $n->sound ? 'cash_register' : 'default'],
                        'data' => ['id' => (string) $n->id, 'type' => $n->type] + array_map('strval', array_filter($n->data ?? [], 'is_scalar')),
                    ]);
                return true;
            } catch (\Throwable $e) {
                Log::warning('Push non envoyé : ' . $e->getMessage());
                return false;
            }
        }

        Log::info("[PUSH → user {$n->user_id}] {$n->title} — {$n->body}");
        return true;
    }
}

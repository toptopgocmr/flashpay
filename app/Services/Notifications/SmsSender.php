<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Passerelle SMS/OTP (§4.2). Driver « log » par défaut, « http » pour un fournisseur réel. */
class SmsSender
{
    public function send(string $phone, string $message): bool
    {
        $driver = config('notifications.sms_driver', 'log');

        if ($driver === 'http' && config('notifications.sms_http.url')) {
            try {
                $r = Http::timeout(10)->withToken((string) config('notifications.sms_http.token'))
                    ->post(config('notifications.sms_http.url'), [
                        'to' => $phone,
                        'from' => config('notifications.sms_http.sender'),
                        'text' => $message,
                    ]);
                return $r->successful();
            } catch (\Throwable $e) {
                Log::warning('SMS non envoyé : ' . $e->getMessage(), ['phone' => $phone]);
                return false;
            }
        }

        Log::info("[SMS → {$phone}] {$message}");
        return true;
    }
}

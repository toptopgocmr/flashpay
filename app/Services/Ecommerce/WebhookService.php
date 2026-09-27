<?php

namespace App\Services\Ecommerce;

use App\Models\MerchantApiKey;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Webhooks serveur à serveur (§4.7.3) signés HMAC-SHA256 :
 *   En-tête FlashPay-Signature: t=<timestamp>,v1=<hex(hmac_sha256(secret, t + "." + body))>
 * Nouvel essai automatique avec délai croissant (1 min → 24 h, 7 tentatives).
 */
class WebhookService
{
    public const BACKOFF_MINUTES = [1, 5, 30, 120, 360, 1440];

    public function dispatch(MerchantApiKey $key, string $event, array $object): ?WebhookDelivery
    {
        if (! $key->webhook_url) {
            return null;
        }
        $d = WebhookDelivery::create([
            'merchant_id' => $key->merchant_id,
            'api_key_id' => $key->id,
            'event' => $event,
            'url' => $key->webhook_url,
            'payload' => [
                'id' => 'evt_' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(20)),
                'type' => $event,
                'created' => now()->timestamp,
                'livemode' => $key->environment === 'live',
                'data' => ['object' => $object],
            ],
            'next_attempt_at' => now(),
            'status' => 'pending',
            'attempts' => 0,
        ]);

        $run = fn () => $this->attempt($d);
        DB::transactionLevel() > 0 ? DB::afterCommit($run) : $run();

        return $d;
    }

    public function sign(string $secret, string $body, int $ts): string
    {
        return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    public function attempt(WebhookDelivery $d): WebhookDelivery
    {
        $d->refresh();
        if ($d->status === 'delivered') {
            return $d;
        }
        $body = json_encode($d->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = now()->timestamp;
        $code = null;
        $error = null;
        try {
            $r = Http::timeout(10)->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'FlashPay-Webhooks/1.0',
                'FlashPay-Signature' => $this->sign($d->apiKey?->webhook_secret ?? '', $body, $ts),
                'FlashPay-Event' => $d->event,
            ])->withBody($body, 'application/json')->post($d->url);
            $code = $r->status();
            $ok = $r->successful();
        } catch (\Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 190);
        }

        $attempts = $d->attempts + 1;
        if ($ok) {
            $d->update(['status' => 'delivered', 'attempts' => $attempts, 'last_response_code' => $code, 'delivered_at' => now(), 'next_attempt_at' => null, 'last_error' => null]);
            if ($d->merchant) {
                app(IntegrationStatusService::class)->refresh($d->merchant);
            }
        } else {
            $delay = self::BACKOFF_MINUTES[$attempts - 1] ?? null;
            $d->update([
                'status' => $delay ? 'pending' : 'failed',
                'attempts' => $attempts,
                'last_response_code' => $code,
                'last_error' => $error ?? "HTTP {$code}",
                'next_attempt_at' => $delay ? now()->addMinutes($delay) : null,
            ]);
        }
        return $d->fresh();
    }

    public function retryDue(): int
    {
        $n = 0;
        WebhookDelivery::where('status', 'pending')->where('next_attempt_at', '<=', now())->where('attempts', '>', 0)->limit(200)->get()
            ->each(function ($d) use (&$n) {
                $this->attempt($d);
                $n++;
            });
        return $n;
    }
}

<?php

namespace App\Services\Security;

use App\Exceptions\BusinessException;
use App\Models\OtpCode;
use App\Services\Notifications\NotificationService;

/** Codes à usage unique envoyés par SMS (inscription, nouvel appareil, PIN, opérations sensibles). */
class OtpService
{
    public const PURPOSES = ['register', 'login_device', 'sensitive', 'pin_reset', 'checkout', 'cash_in'];

    public function __construct(protected NotificationService $notify)
    {
    }

    /** @return array{expires_at:\Illuminate\Support\Carbon, debug_code?:string} */
    public function send(string $phone, string $purpose, array $context = [], ?string $message = null): array
    {
        if (OtpCode::where('phone', $phone)->where('purpose', $purpose)->where('created_at', '>', now()->subSeconds(30))->whereNull('consumed_at')->exists()) {
            throw new BusinessException('Un code vient d\'être envoyé. Patientez 30 secondes avant d\'en redemander un.', 'otp_throttled', 429);
        }

        OtpCode::where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $otp = OtpCode::create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => $this->hash($phone, $code),
            'context' => $context ?: null,
            'expires_at' => now()->addMinutes(config('security.otp_ttl_minutes', 5)),
        ]);

        $text = $message ? str_replace('{code}', $code, $message) : "Votre code FlashPay est {$code}. Ne le communiquez à personne. Valable " . config('security.otp_ttl_minutes', 5) . ' min.';
        $this->notify->sms($phone, $text);

        $out = ['expires_at' => $otp->expires_at];
        if (config('security.otp_debug')) {
            $out['debug_code'] = $code;
        }
        return $out;
    }

    /** Vérifie et consomme le code. Retourne le contexte associé. */
    public function verify(string $phone, string $purpose, ?string $code): array
    {
        $otp = OtpCode::where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw new BusinessException('Code expiré ou inexistant. Demandez un nouveau code.', 'otp_expired');
        }
        if ($otp->attempts >= config('security.otp_max_attempts', 5)) {
            $otp->update(['consumed_at' => now()]);
            throw new BusinessException('Trop de tentatives. Demandez un nouveau code.', 'otp_locked', 429);
        }
        if (! $code || ! hash_equals($otp->code_hash, $this->hash($phone, $code))) {
            $otp->increment('attempts');
            throw new BusinessException('Code incorrect.', 'otp_invalid');
        }

        $otp->update(['consumed_at' => now()]);
        return $otp->context ?? [];
    }

    protected function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone . '|' . $code, (string) config('app.key', 'flashpay'));
    }
}

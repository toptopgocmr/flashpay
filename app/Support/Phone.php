<?php

namespace App\Support;

use App\Services\Peex\PeexCorridors;

/**
 * Numéros de téléphone au format international unique : indicatif pays + numéro,
 * sans « + » en base (ex. 242067621919), affiché « +242067621919 ».
 * « 067621919 », « 06 762 19 19 », « +242 06 762 19 19 » → 242067621919 (Congo par défaut).
 */
class Phone
{
    public static function normalize(?string $phone, ?string $country = null): string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return $raw;
        }
        try {
            return ltrim(app(PeexCorridors::class)->resolve($raw, $country)['phone'], '+');
        } catch (\Throwable) {
            return preg_replace('/\D+/', '', $raw) ?: $raw;
        }
    }

    public static function display(?string $phone): ?string
    {
        return $phone ? '+' . self::normalize($phone) : $phone;
    }

    /** Formes possibles d'un numéro saisi, pour retrouver aussi les comptes enregistrés avant la normalisation. */
    public static function candidates(?string $phone): array
    {
        $raw = trim((string) $phone);
        $n = self::normalize($raw);
        $digits = preg_replace('/\D+/', '', $raw);
        return array_values(array_unique(array_filter([$n, '+' . $n, $raw, $digits])));
    }
}

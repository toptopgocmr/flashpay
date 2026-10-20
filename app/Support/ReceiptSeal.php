<?php

namespace App\Support;

use App\Models\Transaction;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Vignette d'authenticité des reçus (style vignette visa) :
 *  - code de contrôle = HMAC (clé de l'application) des données immuables du reçu
 *    (référence, montant, frais, devise, date) → impossible à fabriquer sans le serveur ;
 *  - QR code vers la page publique de vérification /v/{référence}?c={code}, qui relit
 *    la transaction en base : un reçu modifié (montant, nom…) ne correspond plus ;
 *  - motif guilloché unique par transaction (dérivé du code), comme sur une vignette sécurisée.
 */
class ReceiptSeal
{
    public static function code(Transaction $tx): string
    {
        $data = implode('|', [$tx->reference, (int) $tx->amount, (int) $tx->fee, (string) $tx->currency, (string) $tx->destination_amount,
            (string) $tx->destination_currency, $tx->created_at?->getTimestamp()]);
        $h = strtoupper(hash_hmac('sha256', 'receipt-seal:' . $data, (string) config('app.key')));
        // 10 caractères lisibles (sans 0/O ni 1/I), groupés 5-5
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $out = '';
        foreach (str_split(substr($h, 0, 20), 2) as $pair) {
            $out .= $alphabet[hexdec($pair) % 32];
        }
        return substr($out, 0, 5) . '-' . substr($out, 5, 5);
    }

    public static function valid(Transaction $tx, ?string $code): bool
    {
        $norm = fn ($c) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $c));
        return $code !== null && hash_equals($norm(self::code($tx)), $norm($code));
    }

    public static function verifyUrl(Transaction $tx): string
    {
        return route('receipt.verify', ['reference' => $tx->reference, 'c' => self::code($tx)]);
    }

    /** QR code SVG (aucune extension PHP requise). */
    public static function qrSvg(string $url, int $size = 132): string
    {
        try {
            $svg = (string) QrCode::format('svg')->size($size)->margin(0)->errorCorrection('M')->generate($url);
            return preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Motif guilloché (rosace d'hypotrochoïdes) propre à la transaction, en SVG.
     * Les paramètres découlent du code de contrôle : deux reçus n'ont jamais le même motif.
     */
    public static function guilloche(string $code, int $w = 400, int $h = 170): string
    {
        $seed = crc32($code);
        $rnd = function (int $min, int $max) use (&$seed) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            return $min + $seed % ($max - $min + 1);
        };
        $paths = '';
        $cx = $w * 0.9; // rosace à droite, hors du texte
        $cy = $h / 2;
        $colors = ['#1e3a8a', '#0f766e', '#be123c', '#7c3aed'];
        for ($k = 0; $k < 4; $k++) {
            $R = $rnd(46, 62);
            $r = $rnd(7, 19);
            $d = $rnd(18, 34);
            $pts = [];
            for ($i = 0; $i <= 720; $i++) {
                $t = $i / 720 * 2 * M_PI * $r;
                $x = ($R - $r) * cos($t) + $d * cos(($R - $r) / $r * $t);
                $y = ($R - $r) * sin($t) - $d * sin(($R - $r) / $r * $t);
                $s = 0.55 + $k * 0.12;
                $pts[] = round($cx + $x * $s, 1) . ',' . round($cy + $y * $s, 1);
            }
            $paths .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $colors[$k] . '" stroke-width=".45" stroke-opacity=".55"/>';
        }
        // Ondes de fond (lignes fines croisées)
        for ($j = 0; $j < 14; $j++) {
            $amp = 4 + $j % 5;
            $per = 38 + $rnd(0, 20);
            $y0 = 8 + $j * ($h - 16) / 13;
            $pts = [];
            for ($x = 0; $x <= $w; $x += 4) {
                $pts[] = $x . ',' . round($y0 + $amp * sin($x / $per * 2 * M_PI + $j), 1);
            }
            $paths .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#1e3a8a" stroke-width=".3" stroke-opacity=".28"/>';
        }
        return '<svg class="seal-guilloche" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="xMidYMid slice" aria-hidden="true">' . $paths . '</svg>';
    }

    /** Numéro masqué pour la page publique de vérification : +2420••••••19 */
    public static function mask(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = preg_replace('/\s/', '', $v);
        return mb_strlen($d) > 6 ? mb_substr($d, 0, 5) . str_repeat('•', max(0, mb_strlen($d) - 7)) . mb_substr($d, -2) : '•••';
    }

    /** Nom réduit : « Frédéric O. » */
    public static function shortName(?string $name): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts));
        if (! $parts) {
            return null;
        }
        $first = mb_convert_case(mb_strtolower($parts[0]), MB_CASE_TITLE);
        return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : $first;
    }
}

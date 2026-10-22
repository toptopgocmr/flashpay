<?php

namespace App\Services\Compliance;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Contrôle automatique de la pièce d'identité à l'inscription.
 *
 * Lit la zone MRZ (ICAO 9303 : lignes « <<< » en bas des passeports et des cartes
 * d'identité, standard mondial), vérifie ses chiffres de contrôle, puis compare avec
 * ce que le client a saisi : nom complet et date de naissance. Pièce expirée refusée.
 *
 * Formats : TD1 (cartes, 3 × 30), TD2 (2 × 36), TD3 (passeports, 2 × 44).
 * Le texte vient de la lecture faite par l'app (ML Kit) ou du serveur (Tesseract s'il est installé).
 */
class IdDocumentCheck
{
    /**
     * @param list<string> $texts textes lus sur le recto et le verso
     * @return array{ok:bool, reasons:list<string>, document:?array}
     */
    public function verify(array $texts, string $fullName, ?string $dateOfBirth): array
    {
        $mrz = $this->parse(...$texts);
        if (! $mrz) {
            return ['ok' => false, 'document' => null, 'reasons' => [
                'Pièce illisible : la bande de lecture (lignes « <<< » en bas du passeport ou de la carte) n\'a pas pu être lue. Reprenez une photo nette, bien éclairée et à plat, ou utilisez un passeport ou une carte d\'identité récente.',
            ]];
        }

        $reasons = [];
        if (! $mrz['checks_ok']) {
            $reasons[] = 'Pièce illisible ou non authentique : les codes de contrôle de la bande de lecture ne correspondent pas. Reprenez une photo plus nette.';
        }
        if (! $this->sameName($fullName, $mrz['surname'], $mrz['given_names'])) {
            $reasons[] = 'Le nom saisi (« ' . trim($fullName) . ' ») ne correspond pas à celui de la pièce (« ' . trim($mrz['given_names'] . ' ' . $mrz['surname']) . ' »).';
        }
        if (! $dateOfBirth) {
            $reasons[] = 'Indiquez votre date de naissance.';
        } elseif (! $mrz['date_of_birth'] || $mrz['date_of_birth'] !== Carbon::parse($dateOfBirth)->toDateString()) {
            $reasons[] = 'La date de naissance saisie (' . Carbon::parse($dateOfBirth)->format('d/m/Y') . ') ne correspond pas à celle de la pièce'
                . ($mrz['date_of_birth'] ? ' (' . Carbon::parse($mrz['date_of_birth'])->format('d/m/Y') . ').' : '.');
        }
        if ($mrz['expiry'] && Carbon::parse($mrz['expiry'])->endOfDay()->isPast()) {
            $reasons[] = 'Pièce expirée le ' . Carbon::parse($mrz['expiry'])->format('d/m/Y') . ' : utilisez une pièce en cours de validité.';
        }

        return ['ok' => ! $reasons, 'reasons' => $reasons, 'document' => [
            'type' => $mrz['format'] === 'TD3' ? 'passport' : 'id_card',
            'number' => $mrz['number'],
            'country' => $mrz['country'],
            'nationality' => $mrz['nationality'],
            'surname' => $mrz['surname'],
            'given_names' => $mrz['given_names'],
            'date_of_birth' => $mrz['date_of_birth'],
            'expiry' => $mrz['expiry'],
            'sex' => $mrz['sex'],
        ]];
    }

    // ------------------------------------------------------------ Lecture MRZ

    /** Trouve et décode la MRZ dans un ou plusieurs textes lus. */
    public function parse(string ...$texts): ?array
    {
        $lines = [];
        foreach ($texts as $t) {
            foreach (preg_split('/\R/', (string) $t) as $l) {
                $l = strtoupper(preg_replace('/\s+/', '', $l));
                $l = strtr($l, ['«' => '<<', '‹' => '<', '«' => '<', '(' => '<', '[' => '<', '{' => '<']);
                $l = preg_replace('/[^A-Z0-9<]/', '', $l);
                if (strlen($l) >= 28 && substr_count($l, '<') >= 2) {
                    $lines[] = $l;
                }
            }
        }
        // TD3 (passeport) : 2 lignes de 44, la première commence par P
        foreach ($lines as $i => $l) {
            if (isset($lines[$i + 1]) && $l[0] === 'P' && strlen($l) >= 42 && strlen($lines[$i + 1]) >= 42) {
                return $this->td3(self::fit($l, 44), self::fit($lines[$i + 1], 44));
            }
        }
        // TD1 (carte) : 3 lignes de 30, la première commence par I, A ou C
        foreach ($lines as $i => $l) {
            if (isset($lines[$i + 2]) && in_array($l[0], ['I', 'A', 'C'], true) && strlen($l) <= 32 && strlen($lines[$i + 1]) <= 32 && strlen($lines[$i + 2]) <= 32) {
                return $this->td1(self::fit($l, 30), self::fit($lines[$i + 1], 30), self::fit($lines[$i + 2], 30));
            }
        }
        // TD2 : 2 lignes de 36
        foreach ($lines as $i => $l) {
            if (isset($lines[$i + 1]) && in_array($l[0], ['I', 'A', 'C', 'P', 'V'], true) && strlen($l) >= 34 && strlen($l) <= 38) {
                return $this->td2(self::fit($l, 36), self::fit($lines[$i + 1], 36));
            }
        }
        return null;
    }

    protected function td3(string $l1, string $l2): array
    {
        [$surname, $given] = self::names(substr($l1, 5, 39));
        $num = substr($l2, 0, 9);
        $dob = self::digits(substr($l2, 13, 6));
        $exp = self::digits(substr($l2, 21, 6));
        $checks = self::check($num, $l2[9]) && self::check($dob, $l2[19]) && self::check($exp, $l2[27])
            && self::check(substr($l2, 0, 9) . self::digits($l2[9]) . self::digits(substr($l2, 13, 7)) . self::digits(substr($l2, 21, 7)) . substr($l2, 28, 14) . self::digits($l2[42]), $l2[43]);
        return $this->result('TD3', substr($l1, 2, 3), $num, substr($l2, 10, 3), $dob, $l2[20], $exp, $surname, $given, $checks);
    }

    protected function td2(string $l1, string $l2): array
    {
        [$surname, $given] = self::names(substr($l1, 5, 31));
        $num = substr($l2, 0, 9);
        $dob = self::digits(substr($l2, 13, 6));
        $exp = self::digits(substr($l2, 21, 6));
        $checks = self::check($num, $l2[9]) && self::check($dob, $l2[19]) && self::check($exp, $l2[27])
            && self::check(substr($l2, 0, 9) . self::digits($l2[9]) . self::digits(substr($l2, 13, 7)) . self::digits(substr($l2, 21, 7)) . substr($l2, 28, 7), $l2[35]);
        return $this->result('TD2', substr($l1, 2, 3), $num, substr($l2, 10, 3), $dob, $l2[20], $exp, $surname, $given, $checks);
    }

    protected function td1(string $l1, string $l2, string $l3): array
    {
        [$surname, $given] = self::names($l3);
        $num = substr($l1, 5, 9);
        $dob = self::digits(substr($l2, 0, 6));
        $exp = self::digits(substr($l2, 8, 6));
        $composite = substr($l1, 5, 9) . ($l1[14] === '<' ? '<' : self::digits($l1[14])) . substr($l1, 15, 15) . self::digits(substr($l2, 0, 7)) . self::digits(substr($l2, 8, 7)) . substr($l2, 18, 11);
        $checks = self::check($num, $l1[14]) && self::check($dob, $l2[6]) && self::check($exp, $l2[14]) && self::check($composite, $l2[29]);
        // Numéro long (> 9 caractères) : suite dans le champ facultatif, contrôle à la fin de la suite
        if ($l1[14] === '<') {
            $extra = rtrim(substr($l1, 15, 15), '<');
            $num .= substr($extra, 0, -1);
            $checks = self::check(rtrim(substr($l1, 5, 9), '<') . substr($extra, 0, -1), substr($extra, -1)) && self::check($dob, $l2[6]) && self::check($exp, $l2[14]) && self::check($composite, $l2[29]);
        }
        return $this->result('TD1', substr($l1, 2, 3), $num, substr($l2, 15, 3), $dob, $l2[7], $exp, $surname, $given, $checks);
    }

    protected function result(string $format, string $country, string $number, string $nationality, string $dob, string $sex, string $exp, string $surname, string $given, bool $checks): array
    {
        return [
            'format' => $format,
            'country' => str_replace('<', '', $country),
            'number' => str_replace('<', '', $number),
            'nationality' => str_replace('<', '', $nationality),
            'date_of_birth' => self::date($dob, true),
            'sex' => in_array($sex, ['M', 'F'], true) ? $sex : null,
            'expiry' => self::date($exp, false),
            'surname' => $surname,
            'given_names' => $given,
            'checks_ok' => $checks,
        ];
    }

    // ------------------------------------------------------------ Comparaison des noms

    /**
     * Même personne : mêmes mots (ordre libre, sans accents ni tirets). La MRZ peut couper
     * un nom trop long : le dernier mot de la MRZ peut n'être que le début du mot saisi.
     */
    public function sameName(string $entered, string $surname, string $given): bool
    {
        $e = self::words($entered);
        $m = self::words($surname . ' ' . $given);
        if (! $e || ! $m) {
            return false;
        }
        $left = $e;
        foreach ($m as $i => $w) {
            $k = array_search($w, $left, true);
            if ($k === false && $i === array_key_last($m)) {
                // mot tronqué par la MRZ
                foreach ($left as $kk => $x) {
                    if (strlen($w) >= 3 && str_starts_with($x, $w)) {
                        $k = $kk;
                        break;
                    }
                }
            }
            if ($k === false) {
                return false;
            }
            unset($left[$k]);
        }
        return ! $left; // aucun mot saisi en trop
    }

    /** @return list<string> */
    protected static function words(string $s): array
    {
        $s = strtoupper(Str::ascii($s));
        $s = preg_replace('/[^A-Z]+/', ' ', $s);
        return array_values(array_filter(explode(' ', $s), fn ($w) => $w !== ''));
    }

    // ------------------------------------------------------------ Outils MRZ

    /** @return array{0:string,1:string} nom, prénoms */
    protected static function names(string $field): array
    {
        $field = rtrim($field, '<');
        $parts = explode('<<', $field, 2);
        $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', preg_replace('/[0-9]/', '', (string) $s))));
        return [$clean($parts[0]), $clean($parts[1] ?? '')];
    }

    /** Ligne ramenée à sa longueur (OCR : caractères manquants ou en trop). */
    protected static function fit(string $l, int $len): string
    {
        return strlen($l) >= $len ? substr($l, 0, $len) : str_pad($l, $len, '<');
    }

    /** Champs numériques : confusions OCR courantes (O→0, I→1…). */
    protected static function digits(string $s): string
    {
        return strtr($s, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6']);
    }

    public static function check(string $data, string $digit): bool
    {
        $w = [7, 3, 1];
        $sum = 0;
        foreach (str_split($data) as $i => $c) {
            $v = ctype_digit($c) ? (int) $c : ($c === '<' ? 0 : ord($c) - 55);
            $sum += $v * $w[$i % 3];
        }
        $d = self::digits($digit);
        return ctype_digit($d) && $sum % 10 === (int) $d;
    }

    protected static function date(string $yymmdd, bool $birth): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }
        $yy = (int) substr($yymmdd, 0, 2);
        $now = (int) now()->format('y');
        // Naissance : jamais dans le futur ; expiration : jusqu'à 50 ans dans le futur
        $year = $birth ? ($yy > $now ? 1900 + $yy : 2000 + $yy) : ($yy >= 70 ? 1900 + $yy : 2000 + $yy);
        $m = (int) substr($yymmdd, 2, 2);
        $d = (int) substr($yymmdd, 4, 2);
        return checkdate($m, $d, $year) ? sprintf('%04d-%02d-%02d', $year, $m, $d) : null;
    }

    // ------------------------------------------------------------ Lecture côté serveur (facultative)

    /** Texte lu par Tesseract si le serveur l'a (sinon null). */
    public function ocr(string $path): ?string
    {
        $bin = trim((string) @shell_exec('command -v tesseract 2>/dev/null'));
        if ($bin === '' || ! is_file($path)) {
            return null;
        }
        $out = @shell_exec(escapeshellcmd($bin) . ' ' . escapeshellarg($path) . ' stdout --psm 6 -c tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789\< 2>/dev/null');
        return is_string($out) && trim($out) !== '' ? $out : null;
    }
}

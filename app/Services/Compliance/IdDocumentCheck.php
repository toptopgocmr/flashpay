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
    /** Types de pièces acceptés à l'inscription. */
    public const TYPES = [
        'passport' => 'passeport',
        'id_card' => 'carte d\'identité',
        'niu_card' => 'carte NIU',
        'residence_card' => 'carte de résident',
        'driving_licence' => 'permis de conduire',
    ];

    /**
     * @param list<string> $texts textes lus sur le recto et le verso
     * @param ?string $type type choisi par le client (self::TYPES), null = détection automatique
     * @return array{ok:bool, code:?string, title:?string, reasons:list<string>, read:?array, document:?array}
     */
    public function verify(array $texts, string $fullName, ?string $dateOfBirth, ?string $type = null): array
    {
        $type = isset(self::TYPES[$type]) ? $type : null;
        $label = $type ? self::TYPES[$type] : 'pièce';
        // 1. Bande MRZ (passeports, cartes et titres de séjour à bande « <<< ») ;
        // 2. champs imprimés (carte NIU du Congo, carte de résident, permis de conduire, carte sans bande)
        $mrz = $this->parse(...$texts) ?? $this->parsePrinted($type, ...$texts);
        $fail = fn (string $code, string $title, array $reasons, ?array $read = null) => ['ok' => false, 'code' => $code, 'title' => $title, 'reasons' => $reasons, 'read' => $read, 'document' => null];
        $tips = 'Reprenez la photo du recto bien à plat, sans flash ni reflet (hologramme), avec les 4 coins visibles et le texte net.';
        if (! $mrz) {
            $what = match ($type) {
                'passport' => 'la bande de lecture du passeport (2 lignes « <<< » en bas de la page photo)',
                'id_card' => 'la bande de lecture (lignes « <<< ») ni les champs Nom, Prénom et Date de naissance',
                'niu_card' => 'les champs de la carte NIU (numéro NIU, Nom, Prénom, Date de naissance)',
                'residence_card' => 'la bande de lecture ni les champs Nom, Prénom et Date de naissance de la carte de résident',
                'driving_licence' => 'les champs du permis (1. Nom, 2. Prénoms, 3. Date de naissance)',
                default => 'ni bande de lecture (« <<< ») ni champs Nom, Prénom et Date de naissance',
            };
            return $fail('id_unreadable', 'Pièce illisible', ["Aucune information n'a pu être lue sur la {$label} : {$what} introuvable. {$tips}"]);
        }

        $doc = [
            'type' => match (true) {
                $mrz['format'] === 'TD3' => 'passport',
                in_array($mrz['format'], ['NIU', 'RES', 'DL', 'CNI'], true) => ['NIU' => 'niu_card', 'RES' => 'residence_card', 'DL' => 'driving_licence', 'CNI' => 'id_card'][$mrz['format']],
                $type === 'residence_card' => 'residence_card',
                default => 'id_card',
            },
            'number' => $mrz['number'],
            'country' => $mrz['country'],
            'nationality' => $mrz['nationality'],
            'surname' => $mrz['surname'],
            'given_names' => $mrz['given_names'],
            'date_of_birth' => $mrz['date_of_birth'],
            'expiry' => $mrz['expiry'],
            'sex' => $mrz['sex'],
        ];
        $read = [
            'type' => self::TYPES[$doc['type']],
            'name' => trim($mrz['given_names'] . ' ' . $mrz['surname']) ?: null,
            'date_of_birth' => $mrz['date_of_birth'] ? Carbon::parse($mrz['date_of_birth'])->format('d/m/Y') : null,
            'expiry' => $mrz['expiry'] ? Carbon::parse($mrz['expiry'])->format('d/m/Y') : null,
        ];
        $printed = ! in_array($mrz['format'], ['TD1', 'TD2', 'TD3'], true);
        if ($printed && (! $mrz['surname'] || ! $mrz['date_of_birth'])) {
            $missing = implode(' et ', array_filter([! $mrz['surname'] ? 'le nom' : null, ! $mrz['date_of_birth'] ? 'la date de naissance' : null]));
            return $fail('id_unreadable', 'Pièce mal lue', [ucfirst(self::TYPES[$doc['type']]) . " reconnue, mais {$missing} n'a pas pu être lu. {$tips}"], $read);
        }

        $reasons = [];
        $mismatch = false;
        if (! $mrz['checks_ok']) {
            $reasons[] = 'Bande de lecture mal lue ou pièce non authentique : les codes de contrôle de la bande « <<< » ne correspondent pas. Reprenez une photo plus nette de la bande.';
        }
        if (! $this->sameName($fullName, $mrz['surname'], $mrz['given_names'], $printed)) {
            $mismatch = true;
            $reasons[] = 'Nom différent : vous avez saisi « ' . trim($fullName) . ' », la pièce indique « ' . $read['name'] . ' ». Saisissez exactement le nom et les prénoms de la pièce.';
        }
        if (! $dateOfBirth) {
            $reasons[] = 'Indiquez votre date de naissance.';
        } elseif (! $mrz['date_of_birth'] || $mrz['date_of_birth'] !== Carbon::parse($dateOfBirth)->toDateString()) {
            $mismatch = true;
            $reasons[] = 'Date de naissance différente : vous avez saisi le ' . Carbon::parse($dateOfBirth)->format('d/m/Y')
                . ($read['date_of_birth'] ? ', la pièce indique le ' . $read['date_of_birth'] . '.' : ', elle n\'a pas pu être lue sur la pièce.');
        }
        $expired = $mrz['expiry'] && Carbon::parse($mrz['expiry'])->endOfDay()->isPast();
        if ($expired) {
            $reasons[] = 'Pièce expirée le ' . $read['expiry'] . ' : utilisez une pièce en cours de validité.';
        }
        if (! $reasons) {
            return ['ok' => true, 'code' => null, 'title' => null, 'reasons' => [], 'read' => $read, 'document' => $doc];
        }
        [$code, $title] = match (true) {
            $mismatch => ['id_mismatch', 'Les informations saisies ne correspondent pas à votre ' . self::TYPES[$doc['type']]],
            $expired => ['id_expired', 'Pièce expirée'],
            default => ['id_unreadable', 'Pièce mal lue'],
        };
        return $fail($code, $title, $reasons, $read);
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

    // ------------------------------------------------------------ Carte NIU (Congo)

    /**
     * Pièces sans bande MRZ, lues sur le texte imprimé :
     *  - carte NIU du Congo (Numéro d'Identification Unique : P + chiffres + lettre) ;
     *  - carte de résident / titre de séjour ;
     *  - permis de conduire (champs numérotés 1. Nom, 2. Prénoms, 3. Naissance, 4b. Expiration, 5. Numéro) ;
     *  - carte d'identité sans bande (si le client l'a choisie).
     */
    public function parsePrinted(?string $type, string ...$texts): ?array
    {
        $raw = implode("\n", $texts);
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/', ' ', strtoupper(Str::ascii($l)))), preg_split('/\R/', $raw)), fn ($l) => $l !== ''));
        $all = implode("\n", $lines);
        $niu = preg_match('/\bP\s?([0-9O]{10,16})\s?([A-Z])\b/', $all, $m) ? 'P' . strtr($m[1], ['O' => '0']) . $m[2] : null;

        $kind = match (true) {
            $type === 'driving_licence' || (! $type && (bool) preg_match('/PERMIS DE CONDUIRE|DRIVING LICEN|DRIVER/', $all)) => 'DL',
            $type === 'residence_card' || (! $type && (bool) preg_match('/RESIDEN|SEJOUR/', $all)) => 'RES',
            $type === 'niu_card' || (bool) $niu || (bool) preg_match('/IDENTIFICATION\s+UNIQUE|\bNIU\b/', $all) => 'NIU',
            $type === 'id_card' || (bool) preg_match('/CARTE NATIONALE D.?IDENTITE|IDENTITY CARD/', $all) => 'CNI',
            default => null,
        };
        if (! $kind) {
            return null;
        }

        $isLabel = fn (string $l) => (bool) preg_match('/\b(NOM|NOMS|PRENOM|PRENOMS|SURNAME|GIVEN|NAISSANCE|BIRTH|CREATION|LIEU|NATIONALIT|ADRESSE|DOCUMENT|EXPIR|VALID|VALABLE|SEXE|PROFESSION|DELIVR|NUMERO|CATEGOR|PERMIS|TITRE)\b/', $l);
        // Valeur d'un champ : sur la même ligne après l'étiquette, sinon une des 2 lignes suivantes qui n'est pas une étiquette
        $field = function (string $labelRe, string $valueRe) use ($lines, $isLabel): ?string {
            foreach ($lines as $i => $l) {
                if (! preg_match($labelRe, $l)) {
                    continue;
                }
                $after = trim(preg_replace('/^.*?' . trim($labelRe, '/') . '[A-Z()]*\s*(\/[^A-Z0-9]*[A-Z ()]*)?[:\s]*/', '', $l));
                if ($after !== '' && preg_match($valueRe, $after, $mm) && ! $isLabel($after)) {
                    return trim($mm[0]);
                }
                for ($j = $i + 1; $j <= min($i + 2, count($lines) - 1); $j++) {
                    if (! $isLabel($lines[$j]) && preg_match($valueRe, $lines[$j], $mm)) {
                        return trim($mm[0]);
                    }
                }
            }
            return null;
        };
        // Permis : champ numéroté « 1. NGASSAKI », « 4B. 01.01.2030 »
        $numbered = function (string $n, string $valueRe) use ($lines): ?string {
            foreach ($lines as $l) {
                if (preg_match('/^' . $n . '(?:[.)]\s*|\s+)(.+)$/', $l, $m) && preg_match($valueRe, trim($m[1]), $mm)) {
                    return trim($mm[0]);
                }
            }
            return null;
        };
        $name = '/^[A-Z][A-Z\' -]{1,60}$/';
        $dateRe = '/\b(\d{2})[.\/\- ](\d{2})[.\/\- ](\d{4}|\d{2})\b/';
        $dl = $kind === 'DL';

        $surname = ($dl ? $numbered('1', $name) : null) ?? $field('/\bNOMS?\b(?!.*PRENOM)|\bSURNAME\b/', $name);
        $given = ($dl ? $numbered('2', $name) : null) ?? $field('/\bPRENOMS?\b|\bGIVEN\b/', $name);
        $dob = ($dl ? $numbered('3', $dateRe) : null) ?? $field('/NAISSANCE|BIRTH|\bNEE? LE\b/', $dateRe);
        if (! $dob && ! $dl && preg_match($dateRe, $all, $dm)) {
            $dob = $dm[0]; // NIU / résident : la première date de la carte est la naissance
        }
        $exp = ($dl ? $numbered('4B', $dateRe) : null) ?? $field('/EXPIR|VALIDIT|VALABLE|JUSQU/', $dateRe);
        $doc = ($dl ? $numbered('5', '/\b[A-Z0-9][A-Z0-9 -]{4,20}\b/') : null)
            ?? $field('/DOCUMENT|\bN[O0°]\.?\s|NUMERO|TITRE|PERMIS N/', '/\b[A-Z]{0,3}\d{5,12}[A-Z]?\b/');

        return [
            'format' => $kind,
            'country' => $kind === 'NIU' || str_contains($all, 'CONGO') ? 'COG' : null,
            'number' => $doc ?? $niu,
            'niu' => $niu,
            'nationality' => preg_match('/\bCOG\b/', $all) ? 'COG' : null,
            'date_of_birth' => $dob ? self::vizDate($dob, true) : null,
            'sex' => null,
            'expiry' => $exp ? self::vizDate($exp, false) : null,
            'surname' => $surname ? trim(preg_replace('/\s+/', ' ', $surname)) : '',
            'given_names' => $given ? trim(preg_replace('/\s+/', ' ', $given)) : '',
            'checks_ok' => true, // pas de code de contrôle sur la face imprimée
        ];
    }

    /** Compatibilité : carte NIU. */
    public function parseNiu(string ...$texts): ?array
    {
        $r = $this->parsePrinted('niu_card', ...$texts);
        return $r && ($r['niu'] || preg_match('/IDENTIFICATION\s+UNIQUE|\bNIU\b/', strtoupper(Str::ascii(implode("\n", $texts))))) ? $r : null;
    }

    /** « 30.07.78 » / « 30/07/1978 » → 1978-07-30 */
    protected static function vizDate(string $s, bool $birth): ?string
    {
        if (! preg_match('/(\d{2})[.\/\- ](\d{2})[.\/\- ](\d{4}|\d{2})/', $s, $m)) {
            return null;
        }
        $y = (int) $m[3];
        if (strlen($m[3]) === 2) {
            $now = (int) now()->format('y');
            $y = $birth ? ($y > $now ? 1900 + $y : 2000 + $y) : 2000 + $y;
        }
        return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]) : null;
    }

    // ------------------------------------------------------------ Comparaison des noms

    /**
     * Même personne : mêmes mots (ordre libre, sans accents ni tirets). La MRZ peut couper
     * un nom trop long : le dernier mot de la MRZ peut n'être que le début du mot saisi.
     */
    public function sameName(string $entered, string $surname, string $given, bool $ocrTolerant = false): bool
    {
        $e = self::words($entered);
        $m = self::words($surname . ' ' . $given);
        if (! $e || ! $m) {
            return false;
        }
        $left = $e;
        foreach ($m as $i => $w) {
            $k = array_search($w, $left, true);
            if ($k === false && $ocrTolerant && strlen($w) >= 4) {
                // Carte lue sur sa face imprimée (hologrammes, reflets) : une lettre mal lue est tolérée
                foreach ($left as $kk => $x) {
                    if (abs(strlen($x) - strlen($w)) <= 1 && levenshtein($x, $w) <= 1) {
                        $k = $kk;
                        break;
                    }
                }
            }
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

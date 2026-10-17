<?php

namespace App\Services\Remit;

use App\Models\KycDocument;
use App\Models\OtpCode;
use App\Models\RemitProfile;
use App\Models\User;
use App\Services\Peex\PeexException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * « Activer l'international » : KYC de l'expéditeur collecté une seule fois.
 *
 * Champs exigés par l'union TerraPay + pawaPay + WacePay :
 *   identité   prénom, nom, genre, date de naissance, nationalité
 *   pièce      type, numéro, date d'expiration (pays émetteur, date d'émission : facultatifs)
 *   adresse    pays, ville, adresse (quartier / rue) ; code postal selon le pays de destination
 *   contact    téléphone (compte), e-mail (vérifié par code si REMIT_REQUIRE_EMAIL_VERIFIED)
 *   pièces     recto (ou passeport), verso (carte d'identité), selfie — envoyés via /api/kyc/documents
 * Règle : pièce expirée = transfert bloqué.
 */
class RemitProfileService
{
    public const REQUIRED = [
        'first_name' => 'prénom', 'last_name' => 'nom', 'gender' => 'genre', 'date_of_birth' => 'date de naissance',
        'nationality' => 'nationalité', 'id_type' => 'type de pièce', 'id_number' => 'numéro de pièce',
        'id_expiry' => 'date d\'expiration de la pièce', 'country' => 'pays de résidence', 'city' => 'ville',
        'address' => 'adresse (quartier, rue ou repère)', 'email' => 'e-mail',
        // Exigés par WacePay (sender/create) : déclarés par le client, jamais déduits
        'civility' => 'situation familiale', 'pep' => 'déclaration « personne politiquement exposée »',
    ];

    /** Situation familiale (valeurs WacePay : Single, Maried, Others). */
    public const CIVILITIES = ['Single' => 'Célibataire', 'Married' => 'Marié(e)', 'Others' => 'Autre (divorcé·e, veuf·ve…)'];

    public const OPTIONAL = ['place_of_birth', 'occupation', 'id_issuer_country', 'id_issue_date', 'district', 'street', 'postal_code'];

    public function profileOf(User $user): ?RemitProfile
    {
        return RemitProfile::where('user_id', $user->id)->first();
    }

    /** Données pré-remplies : profil existant, sinon compte FlashPay. */
    public function data(User $user): array
    {
        $p = $this->profileOf($user);
        if ($p) {
            return (array) $p->data + ['email' => $p->email];
        }
        [$first, $last] = InternationalTransferService::splitName((string) $user->full_name);
        $dob = $user->date_of_birth instanceof \DateTimeInterface ? $user->date_of_birth->format('Y-m-d') : ($user->date_of_birth ? substr((string) $user->date_of_birth, 0, 10) : null);
        $home = config('flashpay.remit.from_country', 'CG');
        return array_filter([
            'first_name' => $first, 'last_name' => $last, 'date_of_birth' => $dob, 'place_of_birth' => $user->place_of_birth ?? null,
            'id_number' => $user->id_number, 'nationality' => $home, 'country' => $home, 'id_issuer_country' => $home, 'email' => $user->email,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * État du profil pour un pays de destination (le code postal peut y être exigé).
     * @return array{complete:bool, expired:bool, missing:array, documents:array, email_verified:bool, data:array, completed_at:?string}
     */
    public function status(User $user, ?string $destination = null): array
    {
        $p = $this->profileOf($user);
        $data = $this->data($user);
        $missing = [];
        foreach (self::REQUIRED as $k => $label) {
            // « pep » vaut true ou false : seule l'absence de réponse manque
            if ($k === 'pep' ? ! array_key_exists('pep', $data) || $data['pep'] === null || $data['pep'] === '' : empty($data[$k])) {
                $missing[$k] = $label;
            }
        }
        if ($destination && in_array(strtoupper($destination), (array) config('flashpay.remit.postal_code_countries', []), true) && empty($data['postal_code'])) {
            $missing['postal_code'] = 'code postal';
        }
        $emailVerified = (bool) $p?->email_verified_at && strcasecmp((string) $p->email, (string) ($data['email'] ?? '')) === 0;
        if (config('flashpay.remit.require_email_verified') && ! empty($data['email']) && ! $emailVerified) {
            $missing['email_verified'] = 'vérification de l\'e-mail';
        }
        $docs = $this->documents($user, $data['id_type'] ?? null, $data['id_changed_at'] ?? null);
        if (config('flashpay.remit.require_documents', true)) {
            foreach ($docs as $k => $d) {
                if ($d['required'] && ! in_array($d['status'], ['pending', 'approved'], true)) {
                    $missing['doc_' . $k] = $d['label'];
                }
            }
        }
        $expiry = $data['id_expiry'] ?? null;
        $expired = $expiry && strtotime((string) $expiry) < strtotime('today');

        return [
            'complete' => ! $missing && ! $expired,
            'expired' => (bool) $expired,
            'expires_soon' => $expiry && ! $expired && strtotime((string) $expiry) < strtotime('+30 days'),
            'missing' => $missing,
            'documents' => $docs,
            'email_verified' => $emailVerified,
            'data' => $data,
            'gps' => $p?->gps_lat !== null ? ['lat' => $p->gps_lat, 'lng' => $p->gps_lng, 'at' => $p->gps_at?->toIso8601String()] : null,
            'completed_at' => $p?->completed_at?->toIso8601String(),
        ];
    }

    /** Pièces jointes : recto (ou passeport), verso (carte), selfie — statut du dernier envoi. */
    public function documents(User $user, ?string $idType, ?string $idChangedAt = null): array
    {
        $latest = KycDocument::where('user_id', $user->id)->whereIn('type', ['id_card', 'id_card_back', 'passport', 'selfie', 'profile_photo'])
            ->latest('id')->get()->unique('type')->keyBy('type');
        $passport = strtoupper((string) $idType) === 'PP';
        $front = $passport ? ($latest['passport'] ?? null) : ($latest['id_card'] ?? $latest['passport'] ?? null);
        $out = [
            'front' => ['label' => $passport ? 'photo du passeport' : 'photo de la pièce (recto)', 'type' => $passport ? 'passport' : 'id_card', 'required' => true],
            'back' => ['label' => 'photo de la pièce (verso)', 'type' => 'id_card_back', 'required' => ! $passport],
            'selfie' => ['label' => 'selfie avec la pièce', 'type' => 'selfie', 'required' => true],
            // Photo de profil : modifiable à tout moment (non bloquante)
            'photo' => ['label' => 'photo de profil', 'type' => 'profile_photo', 'required' => false],
        ];
        foreach (['front' => $front, 'back' => $latest['id_card_back'] ?? null, 'selfie' => $latest['selfie'] ?? null, 'photo' => $latest['profile_photo'] ?? null] as $k => $doc) {
            $out[$k]['status'] = $doc?->status ?? 'missing';
            // Photos de l'ancienne pièce : à renvoyer après un changement de pièce
            if ($doc && $idChangedAt && in_array($k, ['front', 'back', 'selfie'], true) && $doc->created_at && $doc->created_at->lt(\Illuminate\Support\Carbon::parse($idChangedAt)->subHour())) {
                $out[$k]['status'] = 'outdated';
                $doc = null;
            }
            $out[$k]['rejection_reason'] = $out[$k]['status'] === 'outdated' ? 'Photo de l\'ancienne pièce : envoyez celle de la nouvelle pièce.' : $doc?->rejection_reason;
        }
        return $out;
    }

    /** Enregistre le profil (fusion avec l'existant). */
    public function save(User $user, array $in): RemitProfile
    {
        $p = $this->profileOf($user) ?? new RemitProfile(['user_id' => $user->id]);
        $data = (array) ($p->data ?? []);
        $before = ['id_number' => $data['id_number'] ?? null, 'id_type' => $data['id_type'] ?? null];
        foreach (array_merge(array_keys(self::REQUIRED), self::OPTIONAL) as $k) {
            if (array_key_exists($k, $in) && $k !== 'email') {
                $v = is_string($in[$k]) ? trim($in[$k]) : $in[$k];
                $data[$k] = $k === 'pep' ? filter_var($v, FILTER_VALIDATE_BOOLEAN)
                    : (in_array($k, ['nationality', 'country', 'id_issuer_country', 'id_type', 'gender'], true) && $v ? strtoupper($v) : $v);
            }
        }
        if (! empty($data['id_expiry']) && strtotime((string) $data['id_expiry']) < strtotime('today')) {
            throw new PeexException('Cette pièce d\'identité est expirée : enregistrez une pièce en cours de validité.');
        }
        if (! empty($data['id_issue_date']) && ! empty($data['id_expiry']) && strtotime((string) $data['id_issue_date']) > strtotime((string) $data['id_expiry'])) {
            throw new PeexException('La date d\'émission de la pièce est postérieure à sa date d\'expiration.');
        }
        // Nouvelle pièce (autre numéro ou autre type) : ses photos sont à renvoyer
        if ($p->exists && ($before['id_number'] || $before['id_type'])
            && (strcasecmp((string) $before['id_number'], (string) ($data['id_number'] ?? '')) !== 0 || $before['id_type'] !== ($data['id_type'] ?? null))) {
            $data['id_changed_at'] = now()->toIso8601String();
        }
        // Adresse « ligne 1 » envoyée aux partenaires : rue / repère + quartier
        $data['address'] = trim(implode(', ', array_filter([$data['street'] ?? null, $data['district'] ?? null]))) ?: ($data['address'] ?? null);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        if (array_key_exists('email', $in)) {
            $email = strtolower(trim((string) $in['email']));
            if ($email !== strtolower((string) $p->email)) {
                $p->email_verified_at = null; // nouvel e-mail : à revérifier
            }
            $p->email = $email ?: null;
        }
        if (isset($in['gps_lat'], $in['gps_lng'])) {
            $p->gps_lat = (float) $in['gps_lat'];
            $p->gps_lng = (float) $in['gps_lng'];
            $p->gps_at = now();
        }
        $p->data = $data;
        $p->id_expiry = $data['id_expiry'] ?? null;
        $p->save();

        // Données utiles au reste du compte (KYC), sans écraser une valeur validée
        $user->forceFill(array_filter([
            'id_number' => $user->id_number ?: ($data['id_number'] ?? null),
            'date_of_birth' => $user->date_of_birth ?: ($data['date_of_birth'] ?? null),
            'place_of_birth' => $user->place_of_birth ?: ($data['place_of_birth'] ?? null),
        ]))->save();

        $st = $this->status($user);
        $p->forceFill(['completed_at' => $st['complete'] ? ($p->completed_at ?? now()) : null])->save();
        \App\Support\Audit::log('remit.profile_saved', $user, ['complete' => $st['complete'], 'missing' => array_keys($st['missing'])], $user->id);
        return $p->fresh();
    }

    // ------------------------------------------------------------ E-mail vérifié par code

    public function sendEmailCode(User $user): array
    {
        $p = $this->profileOf($user);
        $email = (string) ($p?->email ?: $user->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PeexException('Enregistrez d\'abord une adresse e-mail valide.');
        }
        $key = 'mail:' . strtolower($email);
        if (OtpCode::where('phone', $key)->where('purpose', 'email_verify')->where('created_at', '>', now()->subSeconds(30))->whereNull('consumed_at')->exists()) {
            throw new PeexException('Un code vient d\'être envoyé. Patientez 30 secondes.');
        }
        OtpCode::where('phone', $key)->where('purpose', 'email_verify')->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $code = (string) random_int(100000, 999999);
        OtpCode::create([
            'phone' => $key, 'purpose' => 'email_verify',
            'code_hash' => hash_hmac('sha256', $key . '|' . $code, (string) config('app.key', 'flashpay')),
            'expires_at' => now()->addMinutes(15),
        ]);
        try {
            Mail::raw("Votre code de vérification FlashPay est {$code}. Il est valable 15 minutes. Ne le communiquez à personne.", function ($m) use ($email) {
                $m->to($email)->subject('FlashPay — vérification de votre e-mail');
            });
        } catch (\Throwable $e) {
            Log::warning('E-mail de vérification non envoyé', ['error' => $e->getMessage()]);
        }
        $out = ['email' => $email, 'expires_in' => 900];
        if (config('security.otp_debug')) {
            $out['debug_code'] = $code; // comme les codes SMS : affiché dans l'app tant que l'envoi réel n'est pas branché
        }
        return $out;
    }

    public function verifyEmail(User $user, string $code): RemitProfile
    {
        $p = $this->profileOf($user);
        $email = (string) ($p?->email ?: $user->email);
        app(\App\Services\Security\OtpService::class)->verify('mail:' . strtolower($email), 'email_verify', $code);
        if (! $p) {
            $p = $this->save($user, ['email' => $email]);
        }
        $p->forceFill(['email' => strtolower($email), 'email_verified_at' => now()])->save();
        $st = $this->status($user);
        $p->forceFill(['completed_at' => $st['complete'] ? ($p->completed_at ?? now()) : null])->save();
        return $p->fresh();
    }

    // ------------------------------------------------------------ Expéditeur pour les partenaires

    /** Expéditeur « particulier » (ou gérant d'entreprise) tel qu'envoyé aux partenaires. */
    public function sender(User $user): array
    {
        $d = $this->data($user);
        return array_filter([
            'type' => 'P',
            'first_name' => $d['first_name'] ?? null, 'last_name' => $d['last_name'] ?? null,
            'gender' => $d['gender'] ?? null, 'date_of_birth' => $d['date_of_birth'] ?? null, 'place_of_birth' => $d['place_of_birth'] ?? null,
            'nationality' => $d['nationality'] ?? null, 'occupation' => $d['occupation'] ?? null,
            'civility' => $d['civility'] ?? null,
            'id_type' => $d['id_type'] ?? null, 'id_number' => $d['id_number'] ?? null, 'id_expiry' => $d['id_expiry'] ?? null,
            'id_issue_date' => $d['id_issue_date'] ?? null, 'id_issuer_country' => $d['id_issuer_country'] ?? null,
            'address' => $d['address'] ?? null, 'city' => $d['city'] ?? null, 'postal_code' => $d['postal_code'] ?? null,
            'country' => $d['country'] ?? config('flashpay.remit.from_country', 'CG'),
            'phone' => $user->phone, 'email' => $d['email'] ?? $user->email,
        ], fn ($v) => $v !== null && $v !== '') + (array_key_exists('pep', $d) ? ['pep' => (bool) $d['pep']] : []);
    }
}

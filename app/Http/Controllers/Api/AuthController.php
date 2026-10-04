<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Inscription — un seul endpoint, le paramètre "profile" détermine
     * si on crée un compte Client ou un compte Marchand (2 comptes séparés
     * possibles pour le même numéro de téléphone, cf. spec Flutter : deux
     * parcours de connexion distincts dans la même application).
     */
    public function register(Request $request)
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => \App\Support\Phone::normalize($request->input('phone'))]);
        }
        $validated = Validator::make($request->all(), [
            'full_name' => 'required|string|max:150',
            'phone' => 'required|string|unique:users,phone',
            'email' => 'nullable|email|unique:users,email',
            // Code secret de connexion : 4 chiffres minimum (maquettes v2)
            'password' => 'required|string|min:4|confirmed',
            'profile' => 'required|in:client,merchant',
            'business_name' => 'required_if:profile,merchant|string|max:150',
            'business_category' => 'nullable|string|max:80',
            'address' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date|before:today',
            'place_of_birth' => 'nullable|string|max:150',
            'otp' => (config('security.require_otp_on_register') ? 'required' : 'nullable') . '|string|max:10',
            'pin' => 'nullable|string',
            'language' => 'nullable|in:fr,en',
            'device_id' => 'nullable|string|max:120',
        ])->validate();

        // PIN transmis à l'inscription (même code que la connexion) : contrôlé
        // AVANT la création du compte pour ne pas laisser d'utilisateur orphelin.
        if (! empty($validated['pin'])) {
            $pin = $validated['pin'];
            if (! preg_match('/^\d{4,6}$/', $pin) || preg_match('/^(\d)\1+$/', $pin) || in_array($pin, ['1234', '12345', '123456', '0000'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pin' => 'Code secret trop simple. Choisissez un autre code.']);
            }
        }

        // §3.3.1 — inscription simplifiée : numéro vérifié par code OTP
        if (config('security.require_otp_on_register') || ! empty($validated['otp'])) {
            app(\App\Services\Security\OtpService::class)->verify($validated['phone'], 'register', $validated['otp'] ?? null);
        }

        $user = User::create([
            'full_name' => $validated['full_name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'password' => Hash::make($validated['password']),
            'language' => $validated['language'] ?? 'fr',
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'place_of_birth' => $validated['place_of_birth'] ?? null,
            'kyc_tier' => 0,
        ]);
        $user->forceFill(['phone_verified_at' => ! empty($validated['otp']) ? now() : null])->save();

        $user->assignRole($validated['profile']);
        if (! empty($validated['pin'])) {
            app(\App\Services\Security\PinService::class)->set($user, $validated['pin']);
        }

        // Wallet dans la devise du pays du numéro (XAF, XOF, CDF, GNF)
        try {
            $route = app(\App\Services\Peex\PeexCorridors::class)->resolve($validated['phone']);
            $country = $route['country'];
            $currency = $route['currency'];
        } catch (\Throwable) {
            $country = config('flashpay.peex.default_country', 'CG');
            $currency = config('flashpay.base_currency', 'XAF');
        }
        Wallet::create(['user_id' => $user->id, 'balance' => 0, 'currency' => $currency, 'country' => $country]);

        if ($validated['profile'] === 'merchant') {
            Merchant::create([
                'user_id' => $user->id,
                'business_name' => $validated['business_name'],
                'business_category' => $validated['business_category'] ?? null,
                'address' => $validated['address'] ?? null,
                'country' => $country,
                'settlement_phone' => $validated['phone'],
                'qr_code_token' => 'FPM-' . Str::upper(Str::random(12)),
                'validation_status' => 'pending',
            ]);
        }

        $token = app(\App\Services\Security\DeviceService::class)->login($user, $request->all());

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Connexion. Le champ "profile" (client|merchant) permet de choisir
     * quel espace ouvrir si le numéro possède les deux profils.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
            'profile' => 'nullable|in:client,merchant,agent,super_admin,support,cashier',
            'device_id' => 'nullable|string|max:120',
            'otp' => 'nullable|string|max:10',
        ]);

        // Espace agent : connexion possible avec l'identifiant agent (AG000214)
        $login = strtoupper(preg_replace('/[\s-]/', '', $validated['phone']));
        if (preg_match('/^AG\d+$/', $login)) {
            $digits = substr($login, 2);
            $code = 'AG' . str_pad(ltrim($digits, '0') ?: '0', 6, '0', STR_PAD_LEFT);
            $query = User::whereHas('agent', fn ($q) => $q->where('agent_code', $code));
        } else {
            $query = User::whereIn('phone', \App\Support\Phone::candidates($validated['phone']));
        }

        $user = $query->first();
        $secret = (string) $validated['password'];
        $okPassword = $user && Hash::check($secret, $user->password);
        // Le code secret à 4 chiffres et le PIN sont le même code à l'inscription : si seul le PIN
        // a été réinitialisé (ancien « Code oublié »), on l'accepte et on resynchronise le mot de passe.
        $okPin = ! $okPassword && $user && $user->pin_hash && preg_match('/^\d{4,6}$/', $secret) && Hash::check($secret, $user->pin_hash)
            && ! ($user->pin_locked_until && $user->pin_locked_until->isFuture());
        if (! $user || (! $okPassword && ! $okPin)) {
            return response()->json(['message' => 'Numéro ou code secret incorrect.'], 401);
        }
        if ($okPin) {
            $user->forceFill(['password' => Hash::make($secret)])->save();
        }

        if (! empty($validated['profile']) && ! $user->hasRole($validated['profile'])) {
            $labels = ['client' => 'client', 'merchant' => 'marchand', 'agent' => 'agent', 'cashier' => 'caissier', 'super_admin' => 'administrateur', 'support' => 'support'];
            $has = $user->getRoleNames()->map(fn ($r) => $labels[$r] ?? $r)->implode(', ');
            return response()->json(['message' => 'Ce compte n\'a pas l\'accès ' . ($labels[$validated['profile']] ?? $validated['profile']) . ($has ? " (profil : {$has})." : '.') . ' Choisissez le bon espace ou contactez FlashPay.', 'code' => 'wrong_profile'], 403);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Compte suspendu ou bloqué. Contactez le support.'], 403);
        }

        // Téléphone / SIM déclaré perdu ou volé : aucune connexion avant déblocage par le support
        if ($user->lost_reported_at) {
            return response()->json([
                'message' => 'Compte bloqué suite à la déclaration de perte ou de vol du téléphone le ' . $user->lost_reported_at->format('d/m/Y')
                    . '. Présentez-vous dans une agence FlashPay avec votre pièce d\'identité, ou contactez le support, pour le débloquer.',
                'code' => 'account_lost_blocked',
            ], 423);
        }

        if ($user->cashier && $user->cashier->status !== 'active') {
            return response()->json(['message' => 'Accès caissier révoqué par le marchand.'], 403);
        }

        // §15 — connexion depuis un appareil inconnu : confirmation par OTP
        $devices = app(\App\Services\Security\DeviceService::class);
        if (config('security.otp_on_new_device') && ! empty($validated['device_id'])
            && $devices->hasAnyDevice($user) && ! $devices->isKnown($user, $validated['device_id'])
            && ! $user->hasAnyRole(['super_admin', 'support'])) {
            $otp = app(\App\Services\Security\OtpService::class);
            if (empty($validated['otp'])) {
                $sent = $otp->send($user->phone, 'login_device');
                return response()->json(['otp_required' => true, 'message' => 'Nouvel appareil : saisissez le code reçu par SMS.'] + $sent, 202);
            }
            $otp->verify($user->phone, 'login_device', $validated['otp']);
        }

        $token = $devices->login($user, $request->all());
        if ($user->hasRole('cashier')) {
            app(\App\Services\Merchant\CashierService::class)->notifyLogin($user); // §11.3 activité caissier
        }

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token,
        ]);
    }

    /** Envoi d'un code OTP (inscription, nouvel appareil, réinitialisation du PIN). */
    public function requestOtp(Request $request, \App\Services\Security\OtpService $otp)
    {
        $v = $request->validate(['phone' => 'required|string|max:25', 'purpose' => 'required|in:register,pin_reset,login_device']);
        $v['phone'] = \App\Support\Phone::normalize($v['phone']);
        $exists = User::whereIn('phone', \App\Support\Phone::candidates($v['phone']))->exists();
        if ($v['purpose'] === 'register' && $exists) {
            return response()->json(['message' => 'Ce numéro possède déjà un compte FlashPay. Connectez-vous.', 'code' => 'phone_taken'], 422);
        }
        if ($v['purpose'] !== 'register' && ! $exists) {
            // Réponse neutre : ne pas révéler l'existence d'un compte
            return response()->json(['message' => 'Si ce numéro possède un compte, un code a été envoyé.']);
        }
        return response()->json(['message' => 'Code envoyé par SMS.'] + $otp->send($v['phone'], $v['purpose']));
    }

    /**
     * Réinitialisation du PIN oublié — parcours simple : numéro + code SMS + nouveau PIN.
     * Mot de passe et n° de pièce sont facultatifs : vérifiés seulement s'ils sont fournis.
     * Garde-fous : code SMS à usage unique, limitation (throttle), journal d'audit et
     * notification « PIN réinitialisé » à l'utilisateur.
     */
    public function resetPin(Request $request, \App\Services\Security\OtpService $otp, \App\Services\Security\PinService $pins)
    {
        $v = $request->validate([
            'phone' => 'required|string', 'otp' => 'required|string', 'password' => 'nullable|string',
            'id_number' => 'nullable|string|max:60', 'new_pin' => 'required|string',
        ]);
        $user = User::whereIn('phone', \App\Support\Phone::candidates($v['phone']))->first();
        if (! $user) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }
        if (! empty($v['password']) && ! Hash::check($v['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }
        $otp->verify($user->phone, 'pin_reset', $v['otp']);
        if (! empty($v['id_number']) && $user->id_number && strcasecmp(trim($user->id_number), trim((string) ($v['id_number'] ?? ''))) !== 0) {
            return response()->json(['message' => 'Numéro de pièce d\'identité incorrect.', 'code' => 'id_mismatch'], 422);
        }
        $pins->set($user, $v['new_pin']);
        // Code secret unique : le nouveau code sert aussi à la connexion (comme à l'inscription).
        $user->forceFill(['password' => Hash::make($v['new_pin'])])->save();
        $user->tokens()->delete();
        \App\Support\Audit::log('security.pin_reset', $user, [], $user->id);
        app(\App\Services\Notifications\NotificationService::class)->toUser($user, 'pin_changed', 'Code secret réinitialisé', 'Votre code secret (connexion et validation des opérations) a été réinitialisé. Si ce n\'est pas vous, bloquez votre compte immédiatement.', ['severity' => 'warning']);

        return response()->json(['message' => 'Code secret réinitialisé : utilisez-le pour vous connecter.']);
    }

    /**
     * Perte / vol du téléphone ou de la SIM (§15) : blocage immédiat du compte
     * et de tous les appareils pendant la vérification d'identité par le support.
     */
    public function reportLost(Request $request)
    {
        $v = $request->validate(['phone' => 'required|string', 'password' => 'required|string']);
        $user = User::whereIn('phone', \App\Support\Phone::candidates($v['phone']))->first();
        if (! $user || ! Hash::check($v['password'], $user->password)) {
            return response()->json(['message' => 'Numéro ou mot de passe incorrect. Mot de passe oublié ? Contactez le support FlashPay.'], 401);
        }
        if ($user->lost_reported_at) {
            return response()->json(['message' => 'Ce compte est déjà bloqué (perte déclarée le ' . $user->lost_reported_at->format('d/m/Y') . '). Présentez-vous en agence ou contactez le support pour le débloquer.']);
        }
        app(\App\Services\Security\DeviceService::class)->revokeAll($user);
        $user->forceFill(['blocked_until' => now()->addYears(10), 'lost_reported_at' => now()])->save();
        \App\Support\Audit::log('security.report_lost', $user, [], $user->id);
        app(\App\Services\Client\SupportService::class)->openTicket($user, 'security_report', 'Perte / vol du téléphone ou de la SIM', 'Blocage demandé par l\'utilisateur. Vérification d\'identité requise avant déblocage.', 'critical');
        app(\App\Services\Notifications\NotificationService::class)->toAdmins('account_lost', 'Compte bloqué (perte/vol)', "{$user->full_name} ({$user->phone})", ['severity' => 'critical', 'data' => ['user_id' => $user->id]]);

        return response()->json(['message' => 'Votre compte est bloqué et tous vos appareils sont déconnectés. Présentez-vous en agence ou contactez le support pour le débloquer.']);
    }

    // ------------------------------------------------------------ Profil & sécurité

    public function setPin(Request $request, \App\Services\Security\PinService $pins)
    {
        $user = $request->user();
        $v = $request->validate(['pin' => 'required|string', 'current_pin' => 'nullable|string', 'password' => 'nullable|string']);
        if ($user->hasPin()) {
            if (! empty($v['current_pin'])) {
                $pins->check($user, $v['current_pin']);
            } elseif (empty($v['password']) || ! Hash::check($v['password'], $user->password)) {
                return response()->json(['message' => 'PIN actuel ou mot de passe requis.', 'code' => 'reauth_required'], 422);
            }
        }
        $pins->set($user, $v['pin']);
        return response()->json(['message' => 'Code PIN enregistré.', 'has_pin' => true]);
    }

    public function verifyPin(Request $request, \App\Services\Security\PinService $pins)
    {
        $pins->check($request->user(), $request->input('pin'));
        return response()->json(['valid' => true]);
    }

    public function updateProfile(Request $request)
    {
        $v = $request->validate([
            'language' => 'nullable|in:fr,en',
            'email' => 'nullable|email|unique:users,email,' . $request->user()->id,
            'date_of_birth' => 'nullable|date|before:today',
            'place_of_birth' => 'nullable|string|max:150',
        ]);
        $request->user()->update(array_filter($v, fn ($x) => $x !== null));
        return response()->json($this->userPayload($request->user()->fresh()));
    }

    public function devices(Request $request)
    {
        return response()->json($request->user()->devices()->latest('last_seen_at')->get());
    }

    public function updateDevice(Request $request)
    {
        $v = $request->validate(['device_id' => 'required|string|max:120', 'push_token' => 'nullable|string', 'nfc_hce' => 'nullable|boolean']);
        $d = $request->user()->devices()->where('device_id', $v['device_id'])->firstOrFail();
        $d->update(array_filter(['push_token' => $v['push_token'] ?? null, 'nfc_hce' => $v['nfc_hce'] ?? null, 'last_seen_at' => now()], fn ($x) => $x !== null));
        return response()->json($d);
    }

    public function revokeDevice(Request $request, \App\Models\UserDevice $device)
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        app(\App\Services\Security\DeviceService::class)->revoke($request->user(), $device);
        return response()->json(['message' => 'Appareil déconnecté.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Déconnecté.']);
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    protected function userPayload(User $user): array
    {
        $user->load('wallet', 'merchant', 'agent');

        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'kyc_status' => $user->kyc_status,
            'kyc_tier' => (int) $user->kyc_tier,
            'has_pin' => $user->hasPin(),
            'date_of_birth' => optional($user->date_of_birth)->format('Y-m-d'),
            'place_of_birth' => $user->place_of_birth,
            'language' => $user->language ?? 'fr',
            'blocked_until' => $user->blocked_until,
            'limits' => app(\App\Services\Compliance\LimitService::class)->summary($user),
            'cashier' => $user->cashier?->load('merchant:id,business_name', 'outlet:id,name'),
            'unread_notifications' => $user->appNotifications()->whereNull('read_at')->count(),
            'wallet' => $user->wallet,
            'merchant' => $user->merchant,
            'agent' => $user->agent,
            // Habilitations effectives (console « Rôles & habilitations ») : l'app masque le reste
            'permissions' => app(\App\Services\Security\Permissions::class)->granted($user),
        ];
    }
}

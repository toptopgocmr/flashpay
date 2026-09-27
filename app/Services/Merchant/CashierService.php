<?php

namespace App\Services\Merchant;

use App\Exceptions\BusinessException;
use App\Models\Merchant;
use App\Models\MerchantCashier;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Security\DeviceService;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;

/** Sous-comptes caissiers (§3.2.3) : encaissement uniquement, révocation immédiate (§15). */
class CashierService
{
    public function __construct(protected NotificationService $notify, protected DeviceService $devices)
    {
    }

    public function create(Merchant $m, array $d): MerchantCashier
    {
        if (User::whereIn('phone', \App\Support\Phone::candidates($d['phone']))->exists()) {
            throw new BusinessException('Ce numéro possède déjà un compte FlashPay. Utilisez un autre numéro pour le caissier.', 'phone_taken');
        }
        if (! empty($d['outlet_id']) && ! $m->outlets()->whereKey($d['outlet_id'])->exists()) {
            throw new BusinessException('Point de vente inconnu.', 'outlet_not_found');
        }
        $user = User::create([
            'full_name' => $d['full_name'],
            'phone' => $d['phone'],
            'password' => Hash::make($d['password']),
            'kyc_status' => 'verified',
        ]);
        $user->assignRole('cashier');

        $c = MerchantCashier::create(['merchant_id' => $m->id, 'user_id' => $user->id, 'outlet_id' => $d['outlet_id'] ?? null, 'status' => 'active']);
        Audit::log('cashier.create', $c, ['phone' => $d['phone']]);
        return $c->load('user:id,full_name,phone', 'outlet:id,name');
    }

    public function revoke(MerchantCashier $c, ?string $reason = null): MerchantCashier
    {
        $c->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => $reason]);
        $this->devices->revokeAll($c->user); // déconnexion immédiate de tous ses appareils
        Audit::log('cashier.revoke', $c, ['reason' => $reason]);
        return $c;
    }

    public function reactivate(MerchantCashier $c): MerchantCashier
    {
        $c->update(['status' => 'active', 'revoked_at' => null, 'revoked_reason' => null]);
        return $c;
    }

    /** Activité d'un caissier (§11.3) : connexion notifiée au marchand. */
    public function notifyLogin(User $cashierUser): void
    {
        $c = $cashierUser->cashier;
        if ($c) {
            $this->notify->toUser($c->merchant->user, 'cashier_activity', 'Connexion caissier : ' . $cashierUser->full_name, $c->outlet?->name, ['sms' => false]);
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Rôles supportés (Spatie Permission) :
 *   client | merchant | agent | super_admin | support
 *
 * Un même individu peut avoir un compte "client" ET un compte "merchant"
 * séparés (deux enregistrements User distincts), reliés par `linked_user_id`
 * pour permettre un accès croisé rapide dans l'app Flutter.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'full_name', 'email', 'phone', 'password', 'kyc_status', 'status', 'linked_user_id',
        'status_reason', 'status_changed_at', 'status_changed_by',
        'kyc_tier', 'id_number', 'date_of_birth', 'place_of_birth', 'language', 'risk_score', 'blocked_until',
        'pin_hash', 'pin_attempts', 'pin_locked_until', 'pin_changed_at',
    ];

    protected $hidden = ['password', 'remember_token', 'pin_hash'];

    /** Valeur par défaut aussi côté modèle (sinon null avant rechargement depuis la base). */
    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'status_changed_at' => 'datetime', 'kyc_tier' => 'integer', 'pin_locked_until' => 'datetime', 'pin_changed_at' => 'datetime', 'blocked_until' => 'datetime', 'date_of_birth' => 'date'];
    }

    /** Téléphone toujours enregistré au format international (indicatif pays inclus). */
    protected function phone(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: fn ($v) => \App\Support\Phone::normalize($v));
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function merchant()
    {
        return $this->hasOne(Merchant::class);
    }

    public function agent()
    {
        return $this->hasOne(Agent::class);
    }

    public function isClient(): bool
    {
        return $this->hasRole('client');
    }

    public function isMerchant(): bool
    {
        return $this->hasRole('merchant');
    }

    public function cashier()
    {
        return $this->hasOne(MerchantCashier::class);
    }

    public function devices()
    {
        return $this->hasMany(UserDevice::class);
    }

    public function linkedAccounts()
    {
        return $this->hasMany(LinkedAccount::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class);
    }

    public function appNotifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    public function hasPin(): bool
    {
        return ! empty($this->pin_hash);
    }

    /** Profil de plafonds : client | agent | merchant | internal. */
    public function limitProfile(): string
    {
        return match (true) {
            $this->hasRole('agent') => 'agent',
            $this->hasRole('merchant') || $this->hasRole('cashier') => 'merchant',
            $this->hasAnyRole(['super_admin', 'support']) => 'internal',
            default => 'client',
        };
    }
}

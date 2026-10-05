<?php

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Compte technique « Support FlashPay » : côté client, le support est un
 * contact du chat comme un autre (messages, photos, notes vocales, appels).
 * Les agents de la console agissent « au nom » de ce compte ; chaque message
 * envoyé et chaque appel décroché garde l'identité de l'agent (agent_id).
 */
class SupportChat
{
    public const PHONE = 'support';
    public const NAME = 'Support FlashPay';

    protected static ?User $user = null;

    public static function user(): User
    {
        if (static::$user && static::$user->exists) {
            return static::$user;
        }
        return static::$user = User::firstOrCreate(['phone' => self::PHONE], [
            'full_name' => self::NAME,
            // Compte non connectable : mot de passe aléatoire jamais communiqué.
            'password' => Hash::make(Str::random(64)),
            'language' => 'fr',
        ]);
    }

    public static function isSupportUser(?User $u): bool
    {
        return $u !== null && $u->phone === self::PHONE;
    }

    public static function conversationFor(User $client): ChatConversation
    {
        $c = ChatConversation::between($client, self::user());
        if ($c->kind !== 'support') {
            $c->forceFill(['kind' => 'support', 'status' => 'open'])->save();
        }
        return $c;
    }

    /** La requête d'un agent est rejouée au nom du compte support (agent mémorisé pour la traçabilité). */
    public static function actAs(Request $request, User $staff): User
    {
        app()->instance('support.agent', $staff);
        $support = self::user();
        $request->setUserResolver(fn () => $support);
        return $support;
    }

    public static function agent(): ?User
    {
        return app()->bound('support.agent') ? app('support.agent') : null;
    }

    /** Pour les tests (le compte est mis en cache par processus). */
    public static function reset(): void
    {
        static::$user = null;
    }
}

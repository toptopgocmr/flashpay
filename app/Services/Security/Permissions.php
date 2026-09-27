<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Ops\PlatformSettings;
use App\Support\Audit;

/**
 * Habilitations par rôle, modifiables depuis la console (« Rôles & habilitations »).
 *
 *  - Valeurs par défaut : config/roles.php
 *  - Surcharges du Super Admin : paramètre plateforme « role_grants »
 *      [role => [capability => 'yes' | 'no' | ['limited', 'restriction']]]
 *  - Contrôle : middleware « cap:<capability> » sur les routes de l'API ;
 *    la liste des habilitations effectives est aussi renvoyée dans /me pour
 *    que l'application masque les actions retirées.
 *
 * Le Super Admin garde toujours tous ses droits (pas de verrouillage possible).
 */
class Permissions
{
    public const SETTING = 'role_grants';
    public const LOCKED_ROLES = ['super_admin'];

    public function __construct(protected PlatformSettings $settings)
    {
    }

    /** Toutes les habilitations connues : [clé => ['label' => …, 'space' => app|console]]. */
    public function capabilities(): array
    {
        $out = [];
        foreach (config('roles.capabilities', []) as $space => $caps) {
            foreach ($caps as $key => $label) {
                $out[$key] = ['label' => $label, 'space' => $space];
            }
        }
        return $out;
    }

    protected function normalize($v): array
    {
        if ($v === 'yes' || $v === true) {
            return ['value' => 'yes', 'note' => null];
        }
        if (is_array($v) && ($v[0] ?? null) === 'limited') {
            return ['value' => 'limited', 'note' => $v[1] ?? null];
        }
        if (is_array($v) && isset($v['value'])) {
            return ['value' => in_array($v['value'], ['yes', 'limited', 'no'], true) ? $v['value'] : 'no', 'note' => $v['note'] ?? null];
        }
        return ['value' => 'no', 'note' => null];
    }

    /** Surcharges enregistrées. */
    public function overrides(): array
    {
        $o = $this->settings->get(self::SETTING, []);
        return is_array($o) ? $o : [];
    }

    /**
     * Rôles avec habilitations effectives :
     * [role => [...config, 'grants' => [cap => ['value','note','default','overridden']]]]
     */
    public function roles(): array
    {
        $caps = $this->capabilities();
        $over = $this->overrides();
        $out = [];
        foreach (config('roles.roles', []) as $role => $def) {
            $grants = [];
            foreach ($caps as $cap => $meta) {
                if ($meta['space'] !== $def['space']) {
                    continue;
                }
                $default = $this->normalize($def['grants'][$cap] ?? 'no');
                $current = array_key_exists($cap, $over[$role] ?? []) && ! in_array($role, self::LOCKED_ROLES, true)
                    ? $this->normalize($over[$role][$cap])
                    : $default;
                $grants[$cap] = $current + [
                    'default' => $default['value'],
                    'default_note' => $default['note'],
                    'overridden' => $current != $default,
                ];
            }
            $out[$role] = array_merge($def, ['grants' => $grants, 'locked' => in_array($role, self::LOCKED_ROLES, true)]);
        }
        return $out;
    }

    /** Modifie une habilitation d'un rôle (yes | limited | no). */
    public function set(string $role, string $cap, string $value, ?string $note, ?int $by = null): void
    {
        abort_unless(array_key_exists($role, config('roles.roles', [])), 404, 'Rôle inconnu.');
        abort_unless(array_key_exists($cap, $this->capabilities()), 404, 'Habilitation inconnue.');
        abort_if(in_array($role, self::LOCKED_ROLES, true), 422, 'Les droits du Super Admin ne peuvent pas être retirés.');

        $over = $this->overrides();
        $default = $this->normalize(config("roles.roles.{$role}.grants.{$cap}", 'no'));
        $new = ['value' => $value, 'note' => $value === 'limited' ? ($note ?: 'Restreint') : null];
        if ($new['value'] === $default['value'] && ($new['value'] !== 'limited' || $new['note'] === $default['note'])) {
            unset($over[$role][$cap]); // retour à la valeur par défaut
        } else {
            $over[$role][$cap] = $new;
        }
        $over = array_filter($over);
        $this->settings->set(self::SETTING, $over, $by);
        Audit::log('roles.grant', null, ['role' => $role, 'capability' => $cap, 'value' => $value, 'note' => $new['note']], $by);
    }

    /** Rétablit les valeurs par défaut (un rôle ou tous). */
    public function reset(?string $role, ?int $by = null): void
    {
        $over = $this->overrides();
        if ($role) {
            unset($over[$role]);
        } else {
            $over = [];
        }
        $this->settings->set(self::SETTING, array_filter($over), $by);
        Audit::log('roles.reset', null, ['role' => $role ?? 'all'], $by);
    }

    /** Rôles « habilitations » d'un utilisateur (un agent devient agent / sous-agent / super-agent). */
    public function rolesOf(User $user): array
    {
        $roles = [];
        foreach ($user->getRoleNames() as $name) {
            if ($name === 'agent') {
                $a = $user->agent;
                $roles[] = $a?->is_super_agent ? 'super_agent' : ($a?->parent_agent_id ? 'sub_agent' : 'agent');
            } else {
                $roles[] = $name;
            }
        }
        return array_values(array_unique($roles));
    }

    /** Habilitations effectives de l'utilisateur (union de ses rôles). */
    public function granted(User $user): array
    {
        $all = $this->roles();
        $caps = [];
        foreach ($this->rolesOf($user) as $role) {
            if (in_array($role, self::LOCKED_ROLES, true)) {
                return array_keys($this->capabilities());
            }
            foreach ($all[$role]['grants'] ?? [] as $cap => $g) {
                if ($g['value'] !== 'no') {
                    $caps[$cap] = true;
                }
            }
        }
        return array_keys($caps);
    }

    public function allows(User $user, string $cap): bool
    {
        return in_array($cap, $this->granted($user), true);
    }
}

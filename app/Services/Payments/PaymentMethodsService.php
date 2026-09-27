<?php

namespace App\Services\Payments;

use App\Services\Peex\PeexCorridors;

/**
 * Moyens de recharge / retrait / paiement proposés selon le pays choisi.
 * Mobile money : opérateurs du pays (config/corridors.php) opérés via PEEX.
 * Agents / GAB : réseau FlashPay et banques partenaires (config/payment_methods.php).
 */
class PaymentMethodsService
{
    public function __construct(protected PeexCorridors $corridors)
    {
    }

    /** @param string $operation deposit | withdraw | pay */
    public function for(string $country, string $operation): array
    {
        $iso = strtoupper($country);
        $c = $this->corridors->country($iso);
        $defs = config("payment_methods.operations.{$operation}", []);
        $operators = collect($c['operators'] ?? [])->map(fn ($op, $key) => ['corridor' => $key, 'label' => $op['label']])->values()->all();
        $opNames = implode(', ', array_unique(array_column($operators, 'label'))) ?: 'mobile money';

        $methods = [];
        foreach ($defs as $key => $def) {
            [$available, $reason] = $this->availability($def['needs'] ?? null, $iso, $c);
            $methods[] = [
                'key' => $key,
                'label' => $def['label'],
                'description' => str_replace('{operators}', $opNames, $def['description']),
                'icon' => $def['icon'],
                'available' => $available,
                'reason' => $available ? null : $reason,
                'operators' => $key === 'mobile_money' ? $operators : [],
            ];
        }

        // Disponibles d'abord, « bientôt » ensuite
        usort($methods, fn ($a, $b) => $b['available'] <=> $a['available']);

        return [
            'country' => $iso,
            'name' => $c['name'],
            'flag' => $c['flag'] ?? '',
            'currency' => $c['currency'],
            'operation' => $operation,
            'methods' => $methods,
        ];
    }

    public function isAvailable(string $country, string $operation, string $method): bool
    {
        foreach ($this->for($country, $operation)['methods'] as $m) {
            if ($m['key'] === $method) {
                return $m['available'];
            }
        }
        return false;
    }

    public static function cardEnabled(string $iso): bool
    {
        $countries = config('payment_methods.card_countries', []);
        return config('payment_methods.card_driver', 'none') !== 'none'
            && (in_array('*', $countries, true) || in_array(strtoupper($iso), $countries, true));
    }

    protected function availability(?string $needs, string $iso, array $c): array
    {
        return match ($needs) {
            'collect' => [(bool) ($c['collect'] ?? false), 'Recharge mobile money pas encore ouverte dans ce pays'],
            'payout' => [(bool) ($c['payout'] ?? false), 'Retrait mobile money pas encore ouvert dans ce pays'],
            'agents' => [in_array($iso, config('payment_methods.agent_countries', []), true), 'Réseau d\'agents FlashPay bientôt disponible dans ce pays'],
            'atm' => [in_array($iso, config('payment_methods.atm_countries', []), true), 'Bientôt disponible (banque partenaire en cours)'],
            'card' => [self::cardEnabled($iso), 'Paiement par carte bientôt disponible (passerelle carte en cours)'],
            'bank' => [in_array($iso, config('payment_methods.bank_countries', []), true), 'Virement bancaire bientôt disponible (banque partenaire en cours)'],
            default => [true, null],
        };
    }
}

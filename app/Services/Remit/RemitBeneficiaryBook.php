<?php

namespace App\Services\Remit;

use App\Models\RemitBeneficiary;
use App\Models\User;

/**
 * Carnet des bénéficiaires internationaux : enregistré à l'envoi (case cochée
 * par défaut) ou à la main, puis resélectionné pour pré-remplir le transfert.
 * La réponse secrète d'un retrait cash n'est jamais conservée.
 */
class RemitBeneficiaryBook
{
    public const BENEFICIARY_KEYS = ['first_name', 'last_name', 'business_name', 'business_type', 'registration_number', 'phone', 'email', 'city', 'address', 'date_of_birth', 'id_type', 'id_number', 'id_expiry'];
    public const ACCOUNT_KEYS = ['msisdn', 'provider', 'account', 'bank_id', 'bank_name', 'swift', 'branch', 'account_type', 'city', 'collection_code', 'question'];

    /** @param array $v payload validé de /international/send (ou /beneficiaries) */
    public function remember(User $user, array $v, ?string $label = null): RemitBeneficiary
    {
        $ben = $this->only((array) ($v['beneficiary'] ?? []), self::BENEFICIARY_KEYS);
        $acc = $this->only((array) ($v['account'] ?? []), self::ACCOUNT_KEYS);
        $country = strtoupper((string) $v['country']);
        $method = (string) $v['method'];
        $service = isset($v['service']) && is_array($v['service']) ? $this->only($v['service'], ['code', 'name']) : null;

        $key = match ($method) {
            'bank' => strtoupper(preg_replace('/\s+/', '', (string) ($acc['account'] ?? ''))),
            'cash' => mb_strtolower(trim(($ben['first_name'] ?? '') . ' ' . ($ben['last_name'] ?? '') . ' ' . ($ben['business_name'] ?? '') . ' ' . ($ben['phone'] ?? ''))),
            default => preg_replace('/\D/', '', (string) ($acc['msisdn'] ?? '')),
        };
        $fingerprint = hash('sha256', implode('|', [$country, $method, $key ?: json_encode($ben)]));

        $b = RemitBeneficiary::firstOrNew(['user_id' => $user->id, 'fingerprint' => $fingerprint]);
        $old = (array) ($b->data ?? []);
        $b->fill([
            'label' => $label ?: ($b->exists && $b->label ? $b->label : $this->defaultLabel($ben, $acc)),
            'country' => $country,
            'method' => $method,
            'business_type' => (string) ($v['business_type'] ?? 'p2p'),
            'service_name' => $service['name'] ?? $b->service_name,
            'account_hint' => $this->hint($method, $acc, $ben),
            'data' => [
                'beneficiary' => $ben + (array) ($old['beneficiary'] ?? []),
                'account' => $acc,
                'service' => $service ?? ($old['service'] ?? null),
                'relation' => $v['why']['relation'] ?? ($old['relation'] ?? null),
            ],
        ]);
        $b->last_used_at = now();
        $b->save();

        return $b;
    }

    public function touch(RemitBeneficiary $b): void
    {
        $b->forceFill(['uses' => (int) $b->uses + 1, 'last_used_at' => now()])->save();
    }

    protected function only(array $in, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (isset($in[$k]) && $in[$k] !== '') {
                $out[$k] = is_string($in[$k]) ? trim($in[$k]) : $in[$k];
            }
        }
        return $out;
    }

    protected function defaultLabel(array $ben, array $acc): string
    {
        $name = trim(($ben['first_name'] ?? '') . ' ' . ($ben['last_name'] ?? '')) ?: ($ben['business_name'] ?? '');
        return mb_substr($name ?: ($acc['msisdn'] ?? $acc['account'] ?? 'Bénéficiaire'), 0, 120);
    }

    protected function hint(string $method, array $acc, array $ben): ?string
    {
        $mask = function (string $s): string {
            $s = preg_replace('/\s+/', '', $s);
            return strlen($s) <= 4 ? $s : '•••' . substr($s, -4);
        };
        return match ($method) {
            'bank' => trim(($acc['bank_name'] ?? 'Banque') . ' ' . $mask((string) ($acc['account'] ?? ''))),
            'cash' => 'Retrait · ' . ($acc['city'] ?? $ben['city'] ?? ''),
            default => ($acc['msisdn'] ?? null) ? $mask((string) $acc['msisdn']) : null,
        };
    }
}

<?php

namespace App\Services\Beneficiaries;

use App\Models\SavedBeneficiary;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Peex\PeexCorridors;

/**
 * Carnet de bénéficiaires : chaque envoi (wallet FlashPay ou mobile money, tout
 * pays) enregistre ou met à jour le bénéficiaire, pour le resélectionner ensuite
 * dans « Envoyer de l'argent » (nom, numéro, pays, opérateur, compte).
 */
class BeneficiaryBook
{
    public const TYPES = ['p2p'];

    public function remember(Transaction $tx, ?User $user = null): ?SavedBeneficiary
    {
        $user ??= $tx->initiator;
        if (! $user || ! in_array($tx->type, self::TYPES, true) || $tx->status === 'failed') {
            return null;
        }
        $m = $tx->meta ?? [];
        $country = $m['destination_country'] ?? null;
        $operator = $m['destination_operator'] ?? null;
        $operator = is_array($operator) ? ($operator['label'] ?? $operator['name'] ?? null) : $operator;

        if ($tx->destination_wallet_id) {
            $wallet = Wallet::with('user')->find($tx->destination_wallet_id);
            if (! $wallet?->user || $wallet->user_id === $user->id) {
                return null; // recharge de son propre wallet
            }
            $phone = (string) $wallet->user->phone;
            $name = $wallet->user->full_name;
            $deliver = 'wallet';
            $country ??= $wallet->country;
            $operator = 'Wallet FlashPay';
        } elseif ($tx->destination_account && in_array($tx->destination_rail, ['peex', 'digitwace', 'mobile'], true)) {
            $phone = (string) $tx->destination_account;
            $name = $m['beneficiary_name'] ?? $m['beneficiary_verified_name'] ?? null;
            $deliver = 'mobile';
        } else {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '' || $digits === preg_replace('/\D/', '', (string) $user->phone)) {
            return null;
        }
        if (! $country) {
            try {
                $country = app(PeexCorridors::class)->resolve('+' . $digits)['country'] ?? null;
            } catch (\Throwable) {
            }
        }

        $b = SavedBeneficiary::firstOrNew(['user_id' => $user->id, 'phone' => '+' . $digits, 'deliver_to' => $deliver]);
        $when = $tx->created_at ?? now();
        $b->fill(array_filter([
            'name' => $name ?: $b->name,
            'country' => $country ? strtoupper($country) : $b->country,
            'operator' => $operator ?: $b->operator,
            'currency' => $tx->destination_currency ?: $tx->currency,
            'last_amount' => (int) $tx->amount,
            'last_transaction_id' => $tx->id,
        ], fn ($v) => $v !== null && $v !== ''));
        if (! $b->last_used_at || $when->gt($b->last_used_at)) {
            $b->last_used_at = $when;
        }
        $b->uses = (int) $b->uses + 1;
        $b->save();

        return $b;
    }
}

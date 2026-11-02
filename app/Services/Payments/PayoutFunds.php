<?php

namespace App\Services\Payments;

use App\Services\Digitwace\WacepayBalanceService;
use App\Services\Fincra\FincraClient;
use App\Services\Peex\PeexGuard;
use App\Services\Remit\PawapayClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Solde du compte OfficePay chez le partenaire de VERSEMENT, relu sans cache juste avant
 * de débiter le client puis juste avant d'envoyer le versement.
 *
 * Règle : si le compte OfficePay n'est pas assez crédité chez le partenaire (ou si son solde
 * ne peut pas être lu, FLASHPAY_PAYOUT_REQUIRE_BALANCE=true), l'opération est REFUSÉE tout de
 * suite — jamais mise en file d'attente chez le partenaire :
 *   - avant le débit : rien n'est débité, le client voit le message ;
 *   - avant le versement (collecte déjà faite) : autre partenaire si possible, sinon
 *     opération rejetée et payeur remboursé.
 *
 * PEEX production ne communique pas son solde de versement (disbursement_solde = null) :
 * le contrôle PEEX reste celui de PeexGuard (solde quand il est exposé) et PEEX refuse
 * lui-même un versement non provisionné. MaishaPay n'expose pas de solde : un versement
 * non provisionné est refusé immédiatement par MaishaPay (403 « Insufficient Balance »).
 */
class PayoutFunds
{
    public const MESSAGE = 'Solde du partenaire de versement insuffisant : service momentanément indisponible vers ce pays. Réessayez plus tard ou envoyez un montant plus petit.';
    public const UNKNOWN = 'Solde du partenaire de versement non vérifiable : service momentanément indisponible. Réessayez plus tard.';

    /** Message bloquant, ou null si le partenaire peut verser $amount ($currency). */
    public function problem(?string $rail, string $currency, int $amount, ?string $iso = null, ?int $excludeTxId = null): ?string
    {
        $currency = strtoupper($currency);
        $strict = (bool) config('flashpay.payouts.require_balance', true);

        [$avail, $partner] = match ($rail) {
            'peex' => [null, 'PEEX'],
            'digitwace' => config('flashpay.digitwace.check_balance', true) ? [$this->digitwace($currency), 'WacePay'] : [false, 'WacePay'],
            'pawapay_momo' => config('flashpay.pawapay.check_balance', true) ? [$this->pawapay($currency, $iso), 'PawaPay'] : [false, 'PawaPay'],
            'fincra' => config('flashpay.fincra.check_balance', true) ? [$this->fincra($currency), 'Fincra'] : [false, 'Fincra'],
            default => [false, null],
        };

        if ($rail === 'peex') {
            return $iso ? app(PeexGuard::class)->checkPayout($iso, $amount, fresh: true, excludeTxId: $excludeTxId) : null;
        }
        if ($avail === false) {
            return null; // pas de solde à contrôler (partenaire sans API de solde ou contrôle désactivé)
        }
        if ($avail === null) {
            Log::warning("Versement {$partner} : solde {$currency} non lisible", ['amount' => $amount, 'strict' => $strict]);
            if ($strict) {
                $this->alert($partner, "Solde {$partner} ({$currency}) non lisible : versements refusés tant qu'il n'est pas vérifiable.");
                return self::UNKNOWN;
            }
            return null;
        }
        if ($avail < $amount) {
            Log::critical("Versement {$partner} : solde insuffisant", ['currency' => $currency, 'available' => $avail, 'required' => $amount]);
            $this->alert($partner, sprintf('Solde %s insuffisant : %s %s disponibles, %s %s requis. Opérations refusées : réapprovisionner le compte OfficePay chez %s.',
                $partner, number_format($avail, 0, ',', ' '), $currency, number_format($amount, 0, ',', ' '), $currency, $partner));
            return self::MESSAGE;
        }
        return null;
    }

    protected function digitwace(string $currency): ?float
    {
        $b = app(WacepayBalanceService::class)->balances(fresh: true);
        if (! ($b['ok'] ?? false)) {
            return null;
        }
        $acc = collect($b['accounts'] ?? [])->firstWhere('currency', $currency);
        return isset($acc['available']) && is_numeric($acc['available']) ? (float) $acc['available'] : null;
    }

    protected function pawapay(string $currency, ?string $iso): ?float
    {
        $c = app(PawapayClient::class);
        if (! $c->enabled()) {
            return null;
        }
        Cache::forget('pawapay:balances');
        return $c->availableFor($currency, $iso);
    }

    protected function fincra(string $currency): ?float
    {
        $c = app(FincraClient::class);
        if (! $c->enabled()) {
            return null;
        }
        Cache::forget('fincra:balances');
        return $c->availableFor($currency);
    }

    /** Notification console à l'équipe (au plus une fois toutes les 30 min par partenaire). */
    protected function alert(string $partner, string $message): void
    {
        if (! Cache::add('payout-funds-alert:' . $partner, 1, now()->addMinutes(30))) {
            return;
        }
        try {
            app(\App\Services\Notifications\NotificationService::class)
                ->toAdmins('ops', "Versements {$partner} refusés", $message, ['severity' => 'critical']);
        } catch (\Throwable) {
        }
    }
}

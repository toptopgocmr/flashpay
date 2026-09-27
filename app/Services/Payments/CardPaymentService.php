<?php

namespace App\Services\Payments;

use App\Exceptions\CashNetworkException;
use App\Models\Transaction;
use App\Services\SwitchService;
use Illuminate\Support\Str;

/**
 * Paiement par carte Visa / Mastercard (prépayée ou bancaire) via une page de
 * paiement hébergée (3-D Secure). FlashPay ne voit jamais le numéro de carte
 * complet : la passerelle renvoie seulement le résultat et les 4 derniers chiffres.
 *
 *   startCheckout()  transaction « awaiting_card » + URL de la page de paiement
 *   complete()       résultat de la passerelle -> Switch::onSourceConfirmed() / onSourceFailed()
 *
 * Pilotes (FLASHPAY_CARD_DRIVER) :
 *   sandbox  page simulée servie par FlashPay (aucune carte réelle débitée)
 *   none     désactivé (« bientôt disponible »)
 *   <psp>    à brancher quand la passerelle carte sera signée
 */
class CardPaymentService
{
    public function __construct(protected SwitchService $switch)
    {
    }

    public function driver(): string
    {
        return (string) config('payment_methods.card_driver', 'none');
    }

    public function startCheckout(Transaction $tx): Transaction
    {
        if ($this->driver() !== 'sandbox') {
            $tx->update(['status' => 'failed', 'stage' => null, 'failure_reason' => 'Passerelle carte non configurée']);
            throw new CashNetworkException('Le paiement par carte n\'est pas encore disponible.');
        }

        $token = Str::random(40);
        $tx->update([
            'source_external_ref' => $token,
            'meta' => ($tx->meta ?? []) + [
                'card_driver' => $this->driver(),
                'checkout_url' => url("/api/card-checkout/{$token}"),
            ],
        ]);

        return $tx->fresh();
    }

    public function pending(string $token): Transaction
    {
        $tx = Transaction::where('source_external_ref', $token)->first();
        if (! $tx) {
            throw new CashNetworkException('Paiement introuvable.', 404);
        }
        return $tx;
    }

    /** Résultat de la passerelle (idempotent : un 2e appel ne fait rien). */
    public function complete(string $token, bool $ok, ?string $maskedCard = null, ?string $reason = null): Transaction
    {
        $tx = $this->pending($token);

        $claimed = Transaction::whereKey($tx->id)
            ->where('status', 'processing')->where('stage', 'awaiting_card')
            ->update(['stage' => 'awaiting_source', 'source_account' => $maskedCard]);

        if (! $claimed) {
            return $tx->fresh();
        }

        $tx = $tx->fresh();
        return $ok
            ? $this->switch->onSourceConfirmed($tx)
            : $this->switch->onSourceFailed($tx, $reason ?: 'Paiement par carte refusé');
    }

    /** Paiements carte abandonnés (page jamais validée) : annulés après 30 min. */
    public function expireAbandoned(int $minutes = 30): int
    {
        $n = 0;
        Transaction::where('status', 'processing')->where('stage', 'awaiting_card')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->each(function (Transaction $tx) use (&$n) {
                $this->complete($tx->source_external_ref, false, null, 'Paiement par carte abandonné');
                $n++;
            });
        return $n;
    }
}

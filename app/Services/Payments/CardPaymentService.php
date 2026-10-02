<?php

namespace App\Services\Payments;

use App\Exceptions\CashNetworkException;
use App\Models\Transaction;
use App\Services\SwitchService;
use App\Models\DigitwaceRequest;
use App\Services\Digitwace\DigitwaceClient;
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
 *   wacepay  page de paiement WacePay (carte 3-D Secure, ou compte bancaire si FLASHPAY_BANK_DEBIT_DRIVER=wacepay)
 */
class CardPaymentService
{
    public function __construct(protected SwitchService $switch)
    {
    }

    public function driver(?Transaction $tx = null): string
    {
        return $tx && $tx->source_rail === 'bank'
            ? (string) config('payment_methods.bank_debit_driver', 'none')
            : (string) config('payment_methods.card_driver', 'none');
    }

    public function startCheckout(Transaction $tx): Transaction
    {
        $driver = $this->driver($tx);
        $bank = $tx->source_rail === 'bank';
        $label = $bank ? 'La recharge depuis un compte bancaire' : 'Le paiement par carte';
        if (! in_array($driver, ['sandbox', 'wacepay'], true)) {
            $tx->update(['status' => 'failed', 'stage' => null, 'failure_reason' => 'Passerelle ' . ($bank ? 'bancaire' : 'carte') . ' non configurée']);
            throw new CashNetworkException("{$label} n'est pas encore disponible.");
        }

        $token = Str::random(40);
        $ourUrl = url("/api/card-checkout/{$token}");
        $tx->update([
            'source_external_ref' => $token,
            'meta' => ($tx->meta ?? []) + ['card_driver' => $driver, 'checkout_url' => $ourUrl, 'checkout_method' => $bank ? 'bank' : 'card'],
        ]);

        if ($driver === 'wacepay') {
            // Page de paiement WacePay (carte 3-D Secure ou banque) ; retour sur la page FlashPay
            $reference = $tx->reference . '-K' . (DigitwaceRequest::where('transaction_id', $tx->id)->where('operation', 'checkout')->count() + 1);
            $req = DigitwaceRequest::create(['transaction_id' => $tx->id, 'reference' => $reference, 'operation' => 'checkout', 'status' => 'new']);
            try {
                $user = $tx->initiator;
                $res = app(DigitwaceClient::class)->checkout($bank ? 'bank' : 'card', $reference, (int) $tx->amount + (int) $tx->fee, $tx->currency, [
                    'name' => $user?->full_name, 'email' => $user?->email, 'phone' => $user?->phone,
                    'country' => $tx->meta['source_country'] ?? $tx->sourceWallet?->country ?? 'CG',
                    'description' => ($bank ? 'Recharge FlashPay par compte bancaire ' : 'Paiement FlashPay par carte ') . $tx->reference,
                ], $ourUrl);
            } catch (\Throwable $e) {
                $req->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 250), 'finalized_at' => now()]);
                $this->complete($token, false, null, 'WacePay indisponible : ' . $e->getMessage());
                throw new CashNetworkException("{$label} est momentanément indisponible. Aucun montant n'a été débité.");
            }
            $req->update(['wace_id' => $res['wace_id'], 'status' => 'pending', 'last_response' => $res['raw']]);
            if (! $res['url']) {
                $req->update(['status' => 'failed', 'message' => 'Aucune page de paiement renvoyée par WacePay', 'finalized_at' => now()]);
                $this->complete($token, false, null, 'WacePay n\'a pas renvoyé de page de paiement');
                throw new CashNetworkException("{$label} est momentanément indisponible. Aucun montant n'a été débité.");
            }
            $tx->update(['meta' => array_merge($tx->fresh()->meta ?? [], ['checkout_url' => $res['url'], 'return_url' => $ourUrl, 'wacepay_ref' => $reference])]);
        }

        return $tx->fresh();
    }

    /** Résultat WacePay (webhook revérifié ou page de retour). */
    public function syncWacepay(Transaction $tx): Transaction
    {
        $req = DigitwaceRequest::where('transaction_id', $tx->id)->where('operation', 'checkout')->whereNull('finalized_at')->latest('id')->first();
        if ($req) {
            app(\App\Services\Digitwace\DigitwaceStatusHandler::class)->refresh($req);
        }
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
                if (($tx->meta['card_driver'] ?? null) === 'wacepay') {
                    $tx = $this->syncWacepay($tx);
                    if ($tx->stage !== 'awaiting_card') {
                        return;
                    }
                }
                $this->complete($tx->source_external_ref, false, null, 'Paiement par carte abandonné');
                $n++;
            });
        return $n;
    }
}

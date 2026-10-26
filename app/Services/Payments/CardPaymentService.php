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
 * paiement hébergée (3-D Secure). OfficePay ne voit jamais le numéro de carte
 * complet : la passerelle renvoie seulement le résultat et les 4 derniers chiffres.
 *
 *   startCheckout()  transaction « awaiting_card » + URL de la page de paiement
 *   complete()       résultat de la passerelle -> Switch::onSourceConfirmed() / onSourceFailed()
 *
 * Pilotes (FLASHPAY_CARD_DRIVER) :
 *   sandbox  page simulée servie par OfficePay (aucune carte réelle débitée)
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
        $d = match ($tx?->source_rail) {
            'bank' => (string) config('payment_methods.bank_debit_driver', 'none'),
            'crypto' => (string) config('payment_methods.crypto_driver', 'none'),
            default => (string) config('payment_methods.card_driver', 'none'),
        };
        // Sécurité : la page carte SIMULÉE crédite le wallet sans paiement réel.
        // Interdite en production (sauf FLASHPAY_ALLOW_SANDBOX_CARD=true pour une recette).
        return $d === 'sandbox' && self::sandboxForbidden() ? 'none' : $d;
    }

    public static function sandboxForbidden(): bool
    {
        return app()->environment('production') && ! filter_var(env('FLASHPAY_ALLOW_SANDBOX_CARD', false), FILTER_VALIDATE_BOOL);
    }

    public function startCheckout(Transaction $tx): Transaction
    {
        $driver = $this->driver($tx);
        $bank = $tx->source_rail === 'bank';
        $crypto = $tx->source_rail === 'crypto';
        $label = $crypto ? 'La recharge en crypto-monnaie' : ($bank ? 'La recharge depuis un compte bancaire' : 'Le paiement par carte');
        if (! in_array($driver, ['sandbox', 'wacepay', 'maishapay'], true) || ($driver === 'maishapay' && $bank) || ($crypto && $driver !== 'maishapay')) {
            $tx->update(['status' => 'failed', 'stage' => null, 'failure_reason' => 'Passerelle ' . ($crypto ? 'crypto' : ($bank ? 'bancaire' : 'carte')) . ' non configurée']);
            throw new CashNetworkException("{$label} n'est pas encore disponible.");
        }

        $token = Str::random(40);
        $ourUrl = url("/api/card-checkout/{$token}");
        $tx->update([
            'source_external_ref' => $token,
            'meta' => ($tx->meta ?? []) + ['card_driver' => $driver, 'checkout_url' => $ourUrl, 'checkout_method' => $crypto ? 'crypto' : ($bank ? 'bank' : 'card')],
        ]);

        if ($driver === 'wacepay') {
            // Page de paiement WacePay (carte 3-D Secure ou banque) ; retour sur la page OfficePay
            $reference = $tx->reference . '-K' . (DigitwaceRequest::where('transaction_id', $tx->id)->where('operation', 'checkout')->count() + 1);
            $req = DigitwaceRequest::create(['transaction_id' => $tx->id, 'reference' => $reference, 'operation' => 'checkout', 'status' => 'new']);
            try {
                $user = $tx->initiator;
                $res = app(DigitwaceClient::class)->checkout($bank ? 'bank' : 'card', $reference, (int) $tx->amount + (int) $tx->fee, $tx->currency, [
                    'name' => $user?->full_name, 'email' => $user?->email, 'phone' => $user?->phone,
                    'country' => $tx->meta['source_country'] ?? $tx->sourceWallet?->country ?? 'CG',
                    'description' => ($bank ? 'Recharge OfficePay par compte bancaire ' : 'Paiement OfficePay par carte ') . $tx->reference,
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

        if ($driver === 'maishapay') {
            // Page de paiement MaishaPay (carte 3-D Secure ou crypto) ; retour et notification sur la page OfficePay
            $reference = $tx->reference . '-K' . (\App\Models\RemitRequest::where('transaction_id', $tx->id)->where('method', 'checkout')->count() + 1);
            $req = \App\Models\RemitRequest::create(['transaction_id' => $tx->id, 'partner' => 'maishapay', 'reference' => $reference, 'method' => 'checkout', 'status' => 'new']);
            try {
                $user = $tx->initiator;
                $names = preg_split('/\s+/', trim((string) ($user?->full_name ?: 'Client OfficePay'))) ?: ['Client'];
                $client = app(\App\Services\Maishapay\MaishapayClient::class);
                // MaishaPay ajoute lui-même ses frais au payeur sur sa page : on ne les demande pas deux fois
                $charge = (int) $tx->amount + (int) $tx->fee;
                $collectFee = (int) data_get($tx->meta, 'fee_breakdown.collect', 0);
                if ($collectFee > 0 && $collectFee < $charge && \App\Support\PartnerFees::payerBorne($crypto ? 'maishapay_crypto' : 'maishapay_card')) {
                    $charge -= $collectFee;
                }
                $res = $crypto ? $client->collectCrypto($reference, [
                    'amount' => $charge, 'currency' => $tx->currency, 'asset' => $tx->meta['crypto_asset'] ?? null,
                    'first_name' => array_shift($names), 'last_name' => implode(' ', $names) ?: 'OfficePay',
                    'phone' => $user?->phone, 'email' => $user?->email, 'return_url' => $ourUrl,
                ]) : $client->collectCard($reference, [
                    'amount' => $charge, 'currency' => $tx->currency, 'brand' => $tx->meta['card_brand'] ?? null,
                    'first_name' => array_shift($names), 'last_name' => implode(' ', $names) ?: 'OfficePay',
                    'phone' => $user?->phone, 'email' => $user?->email, 'address' => $user ? ($user->getAttributes()['address'] ?? null) : null, 'city' => $user ? ($user->getAttributes()['city'] ?? null) : null,
                    'return_url' => $ourUrl,
                ]);
            } catch (\Throwable $e) {
                $req->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 250), 'finalized_at' => now()]);
                $this->complete($token, false, null, 'MaishaPay indisponible : ' . $e->getMessage());
                throw new CashNetworkException("{$label} est momentanément indisponible. Aucun montant n'a été débité.");
            }
            $req->update(['partner_ref' => $res['id'] ?: $reference, 'status' => 'pending', 'raw_status' => $res['raw_status'], 'last_response' => $res['raw']]);
            if (! $res['url']) {
                $req->update(['status' => 'failed', 'message' => 'Aucune page de paiement renvoyée par MaishaPay', 'finalized_at' => now()]);
                $this->complete($token, false, null, 'MaishaPay n\'a pas renvoyé de page de paiement');
                throw new CashNetworkException("{$label} est momentanément indisponible. Aucun montant n'a été débité.");
            }
            // Carte : la page MaishaPay n'est qu'un formulaire signé envoyé à CyberSource. OfficePay le relaie
            // depuis son propre domaine (/pay) : sur certains téléphones / réseaux, la connexion sécurisée à
            // marchand.maishapay.online échoue (ERR_SSL_PROTOCOL_ERROR) alors que CyberSource répond.
            $tx->update(['meta' => array_merge($tx->fresh()->meta ?? [], [
                'checkout_url' => $crypto ? $res['url'] : $ourUrl . '/pay', 'maishapay_page' => $res['url'],
                'return_url' => $ourUrl, 'maishapay_ref' => $reference,
            ])]);
        }

        return $tx->fresh();
    }

    /** Résultat MaishaPay (notification revérifiée ou page de retour). */
    public function syncMaishapay(Transaction $tx): Transaction
    {
        $req = \App\Models\RemitRequest::where('transaction_id', $tx->id)->where('partner', 'maishapay')->where('method', 'checkout')->whereNull('finalized_at')->latest('id')->first();
        if ($req) {
            app(\App\Services\Remit\RemitStatusHandler::class)->refresh($req);
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

        if (! $claimed && $ok) {
            // Paiement confirmé APRÈS l'expiration de la page (client lent, 3-D Secure tardif) :
            // la carte a bien été débitée → on rouvre l'opération pour créditer le client.
            $late = Transaction::whereKey($tx->id)->where('status', 'failed')->first();
            if ($late && ! empty(($late->meta ?? [])['card_expired'])) {
                \Illuminate\Support\Facades\Log::warning('Carte : paiement confirmé après expiration — opération rouverte', ['reference' => $late->reference]);
                $late->forceFill(['status' => 'processing', 'stage' => 'awaiting_card', 'failure_reason' => null,
                    'meta' => array_merge($late->meta ?? [], ['card_late_success' => now()->toIso8601String()])])->saveQuietly();
                $claimed = Transaction::whereKey($tx->id)->where('status', 'processing')->where('stage', 'awaiting_card')
                    ->update(['stage' => 'awaiting_source', 'source_account' => $maskedCard]);
            }
        }
        if (! $claimed) {
            return $tx->fresh();
        }

        $tx = $tx->fresh();

        // Règle « comptes liés » : la carte payée doit être la carte liée au profil.
        // Le paiement est déjà encaissé par la passerelle : on crédite, mais on signale
        // tout écart à l'équipe (contrôle anti-fraude).
        $expected = (string) ($tx->meta['expected_card_last4'] ?? '');
        $used = $maskedCard && preg_match('/(\d{4})\D*$/', $maskedCard, $mm) ? $mm[1] : null;
        if ($ok && $expected !== '' && $used !== null && $used !== $expected) {
            $tx->update(['meta' => array_merge($tx->meta ?? [], ['card_mismatch' => true, 'card_used_last4' => $used])]);
            try {
                $owner = \App\Models\User::find($tx->initiated_by);
                if ($owner) {
                    app(\App\Services\Compliance\FraudService::class)->cardMismatch($owner, $tx, $used, $expected);
                }
            } catch (\Throwable) {
            }
        }

        return $ok
            ? $this->switch->onSourceConfirmed($tx)
            : $this->switch->onSourceFailed($tx, $reason ?: 'Paiement par carte refusé');
    }

    /**
     * Paiements carte / crypto abandonnés (page jamais validée) : statut relu chez la passerelle,
     * puis annulés après FLASHPAY_CARD_TIMEOUT_MINUTES (2 min par défaut). Un paiement confirmé
     * plus tard par la passerelle rouvre l'opération (voir complete()).
     */
    public static function timeoutMinutes(): int
    {
        return max(2, (int) config('payment_methods.card_checkout_timeout_minutes', 5));
    }

    /** Échéance de la page de paiement carte / crypto (compte à rebours dans l'app). */
    public static function deadline(Transaction $tx): ?\Illuminate\Support\Carbon
    {
        if ($tx->status !== 'processing' || $tx->stage !== 'awaiting_card' || ! $tx->created_at) {
            return null;
        }
        return $tx->created_at->copy()->addMinutes(self::timeoutMinutes());
    }

    /** Lecture du statut par l'app : délai dépassé → statut relu chez la passerelle puis annulation, sans attendre le planificateur. */
    public function expireIfAbandoned(Transaction $tx): Transaction
    {
        $d = self::deadline($tx);
        if (! $d || $d->isFuture()) {
            return $tx;
        }
        $this->expireAbandoned(null, $tx->id);
        return $tx->fresh();
    }

    public function expireAbandoned(?int $minutes = null, ?int $onlyId = null): int
    {
        $minutes ??= self::timeoutMinutes();
        $n = 0;
        Transaction::where('status', 'processing')->where('stage', 'awaiting_card')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->when($onlyId, fn ($q) => $q->whereKey($onlyId))
            ->each(function (Transaction $tx) use (&$n, $minutes) {
                if (in_array($tx->meta['card_driver'] ?? null, ['wacepay', 'maishapay'], true)) {
                    $tx = ($tx->meta['card_driver'] ?? null) === 'maishapay' ? $this->syncMaishapay($tx) : $this->syncWacepay($tx);
                    if ($tx->stage !== 'awaiting_card') {
                        return;
                    }
                }
                $tx->forceFill(['meta' => array_merge($tx->meta ?? [], ['card_expired' => true])])->saveQuietly();
                $this->complete($tx->source_external_ref, false, null, ($tx->source_rail === 'crypto' ? 'Paiement crypto' : 'Paiement par carte')
                    . " non finalisé dans les {$minutes} minutes : opération annulée, aucun montant n'a été débité");
                $n++;
            });
        return $n;
    }
}

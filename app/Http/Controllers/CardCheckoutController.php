<?php

namespace App\Http\Controllers;

use App\Services\Payments\CardPaymentService;
use Illuminate\Http\Request;

/**
 * Page de paiement carte SIMULÉE (FLASHPAY_CARD_DRIVER=sandbox), en attendant
 * la passerelle carte réelle. Aucune carte n'est débitée.
 * Cartes de test : 4242 4242 4242 4242 (acceptée), 4000 0000 0000 0002 (refusée),
 * 4000 0000 0000 9995 (fonds insuffisants). Mastercard : 5555 5555 5555 4444.
 */
class CardCheckoutController extends Controller
{
    public function __construct(protected CardPaymentService $cards)
    {
    }

    public function show(string $token)
    {
        $tx = $this->cards->pending($token);
        $driver = $tx->meta['card_driver'] ?? $this->cards->driver($tx);
        if ($driver === 'wacepay') {
            // Retour de la page WacePay : on vérifie le résultat auprès de l'API
            $tx = $this->cards->syncWacepay($tx);
            return response($this->page($tx, $token, null, true));
        }
        abort_unless($driver === 'sandbox', 404);

        return response($this->page($tx, $token));
    }

    public function submit(Request $request, string $token)
    {
        abort_unless(($this->cards->pending($token)->meta['card_driver'] ?? null) === 'sandbox', 404);

        if ($request->input('action') === 'cancel') {
            $tx = $this->cards->complete($token, false, null, 'Paiement par carte annulé');
            return response($this->page($tx, $token));
        }

        $number = preg_replace('/\D/', '', (string) $request->input('card_number'));
        $error = match (true) {
            strlen($number) < 13 || ! $this->luhn($number) => 'Numéro de carte invalide.',
            ! preg_match('#^(0[1-9]|1[0-2])\s*/\s*\d{2}$#', (string) $request->input('expiry')) => 'Date d\'expiration invalide (MM/AA).',
            ! preg_match('/^\d{3,4}$/', (string) $request->input('cvc')) => 'Code CVC invalide.',
            default => null,
        };
        if ($error) {
            return response($this->page($this->cards->pending($token), $token, $error), 422);
        }

        $brand = str_starts_with($number, '4') ? 'Visa' : (preg_match('/^(5[1-5]|2[2-7])/', $number) ? 'Mastercard' : 'Carte');
        $masked = $brand . ' •••• ' . substr($number, -4);
        [$ok, $reason] = match ($number) {
            '4000000000000002' => [false, 'Carte refusée par la banque'],
            '4000000000009995' => [false, 'Fonds insuffisants sur la carte'],
            default => [true, null],
        };

        $tx = $this->cards->complete($token, $ok, $masked, $reason);
        return response($this->page($tx, $token));
    }

    protected function luhn(string $n): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $d = (int) $n[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = ! $alt;
        }
        return $sum % 10 === 0;
    }

    protected function page($tx, string $token, ?string $error = null, bool $wacepay = false): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $amount = number_format($tx->amount + $tx->fee, 0, ',', ' ') . ' ' . $tx->currency;
        $action = url("/api/card-checkout/{$token}");

        if ($wacepay && $tx->status === 'processing' && $tx->stage === 'awaiting_card') {
            $retry = $e($tx->meta['checkout_url'] ?? '');
            $body = '<div class="icon wait">…</div><h1>Paiement en cours de vérification</h1>'
                . '<p>' . $e($amount) . ' · Réf. ' . $e($tx->reference) . '</p>'
                . '<p class="muted">Le résultat est confirmé par WacePay en quelques instants. Vous pouvez revenir dans l\'application FlashPay : vous serez notifié.</p>'
                . '<form method="get"><button>Actualiser</button></form>'
                . ($retry ? '<p><a href="' . $retry . '">Retourner sur la page de paiement</a></p>' : '');
        } elseif ($tx->status !== 'processing' || $tx->stage !== 'awaiting_card') {
            $ok = in_array($tx->status, ['successful', 'processing'], true);
            $body = '<div class="icon ' . ($ok ? 'ok' : 'ko') . '">' . ($ok ? '✓' : '✕') . '</div>'
                . '<h1>' . ($ok ? 'Paiement accepté' : 'Paiement non abouti') . '</h1>'
                . '<p>' . $e($ok ? "{$amount} débités de " . ($tx->source_rail === 'bank' ? 'votre compte bancaire' : 'votre carte') . " {$tx->source_account}." : ($tx->failure_reason ?: 'Paiement refusé.')) . '</p>'
                . '<p class="muted">Vous pouvez fermer cette page et revenir dans l\'application FlashPay.</p>';
        } else {
            $body = '<p class="muted">Montant à payer</p><div class="amount">' . $e($amount) . '</div>'
                . '<p class="muted">Réf. ' . $e($tx->reference) . ' · frais carte inclus</p>'
                . ($error ? '<div class="err">' . $e($error) . '</div>' : '')
                . '<form method="post" action="' . $e($action) . '">'
                . '<label>Numéro de carte<input name="card_number" inputmode="numeric" autocomplete="cc-number" placeholder="4242 4242 4242 4242" required></label>'
                . '<div class="row"><label>Expiration<input name="expiry" placeholder="MM/AA" required></label>'
                . '<label>CVC<input name="cvc" inputmode="numeric" placeholder="123" required></label></div>'
                . '<button name="action" value="pay">Payer ' . $e($amount) . '</button>'
                . '<button name="action" value="cancel" class="link" formnovalidate>Annuler</button></form>'
                . '<details><summary>Cartes de test</summary><p>4242 4242 4242 4242 (Visa acceptée) · 5555 5555 5555 4444 (Mastercard acceptée) · 4000 0000 0000 0002 (refusée) · 4000 0000 0000 9995 (fonds insuffisants). Expiration future, CVC quelconque.</p></details>';
        }

        $badge = $wacepay ? '<span>WacePay</span>' : '<span>SANDBOX — aucune carte réelle débitée</span>';

        return <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FlashPay · Paiement par carte</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f5f7;color:#1b1240}
.bar{background:#1b1240;color:#fff;padding:14px 20px;font-weight:800;display:flex;justify-content:space-between;align-items:center}
.bar span{background:#ffb703;color:#1b1240;border-radius:6px;padding:2px 8px;font-size:12px}
.card{max-width:420px;margin:24px auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
.amount{font-size:32px;font-weight:900;margin:4px 0}.muted{color:#6b7280;font-size:13px;margin:4px 0}
label{display:block;font-size:13px;font-weight:600;margin-top:14px}
input{width:100%;box-sizing:border-box;margin-top:6px;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:16px}
.row{display:flex;gap:12px}.row label{flex:1}
button{width:100%;margin-top:20px;padding:14px;border:0;border-radius:12px;background:#1b1240;color:#fff;font-size:16px;font-weight:800;cursor:pointer}
button.link{background:none;color:#6b7280;margin-top:8px;font-weight:600}
.err{background:#fff1f1;color:#b91c1c;padding:10px;border-radius:8px;margin-top:12px;font-size:14px}
details{margin-top:16px;font-size:12px;color:#6b7280}
.icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;color:#fff;margin:0 auto 8px}
.icon.ok{background:#16a34a}.icon.ko{background:#dc2626}.icon.wait{background:#f59e0b}h1{text-align:center;font-size:22px}
.card>p{text-align:center}
</style></head><body>
<div class="bar">FlashPay · Paiement sécurisé {$badge}</div>
<div class="card">{$body}</div>
</body></html>
HTML;
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\GiftEnvelope;
use App\Models\PaymentIntent;
use App\Services\Ecommerce\PaymentIntentService;
use App\Services\Merchant\PaymentRequestService;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use App\Services\Security\OtpService;
use App\Services\Security\PinService;
use Illuminate\Http\Request;

/**
 * Pages web publiques :
 *   /checkout/{pi_…}  page de paiement e-commerce (§4.7.4) : ouverture de l'app
 *                     ou paiement web (numéro → code SMS → PIN FlashPay)
 *   /p/{token}        lien de paiement marchand (§3.2.2) → ouverture dans l'app
 *   /g/{code}         cadeau d'argent (§3.5.1) → ouverture dans l'app
 *   /docs/api-ecommerce  documentation d'intégration
 */
class PublicPagesController extends Controller
{
    public function checkout(string $id, PaymentIntentService $intents)
    {
        $pi = PaymentIntent::with('merchant')->where('public_id', $id)->firstOrFail();
        return response($this->checkoutPage($pi, $intents->present($pi)));
    }

    public function checkoutSubmit(Request $request, string $id, PaymentIntentService $intents, OtpService $otp, PinService $pins, PeexCorridors $corridors, PeexFlowService $flows)
    {
        $pi = PaymentIntent::with('merchant')->where('public_id', $id)->firstOrFail();
        $step = $request->input('step', 'phone');
        try {
            if ($request->input('action') === 'cancel') {
                if ($pi->status === 'requires_confirmation') {
                    $pi->update(['status' => 'canceled']);
                }
                return $pi->cancel_url ? redirect()->away($pi->cancel_url) : response($this->checkoutPage($pi->fresh(), $intents->present($pi->fresh())));
            }
            $phone = $corridors->resolve((string) $request->input('phone'))['phone'];
            $user = $flows->findUserByPhone($phone);
            if (! $user) {
                throw new BusinessException('Aucun compte FlashPay pour ce numéro.');
            }
            if ($step === 'phone') {
                $sent = $otp->send($user->phone, 'checkout', ['pi' => $pi->public_id]);
                return response($this->checkoutPage($pi, $intents->present($pi), null, ['step' => 'otp', 'phone' => $user->phone, 'debug' => $sent['debug_code'] ?? null]));
            }
            $ctx = $otp->verify($user->phone, 'checkout', (string) $request->input('otp'));
            if (($ctx['pi'] ?? null) !== $pi->public_id) {
                throw new BusinessException('Code invalide pour ce paiement.');
            }
            $pins->check($user, (string) $request->input('pin'));
            $pi = $intents->payByUser($user, $pi);
            if ($pi->status === 'succeeded' && $pi->return_url) {
                return redirect()->away($pi->return_url . (str_contains($pi->return_url, '?') ? '&' : '?') . 'payment_intent=' . $pi->public_id . '&status=confirmed');
            }
            return response($this->checkoutPage($pi, $intents->present($pi)));
        } catch (BusinessException $e) {
            return response($this->checkoutPage($pi, $intents->present($pi->fresh()), $e->getMessage(), ['step' => $step === 'phone' ? 'phone' : 'otp', 'phone' => $request->input('phone')]), 422);
        }
    }

    public function paymentLink(string $token, PaymentRequestService $requests)
    {
        try {
            $r = $requests->present($requests->find($token));
        } catch (BusinessException) {
            abort(404);
        }
        $amount = number_format($r['amount'], 0, ',', ' ') . ' ' . $r['currency'];
        $state = match ($r['status']) { 'paid' => '<p class="ok">✔ Déjà payé</p>', 'pending' => '', default => '<p class="err">Ce lien n\'est plus valide (' . e($r['status']) . ').</p>' };
        $btn = $r['status'] === 'pending' ? '<a class="btn" href="' . e($r['qr_payload']) . '">Payer avec FlashPay</a><p class="muted">Ou scannez ce lien depuis l\'app FlashPay › Payer.</p>' : '';
        return response($this->layout('Paiement ' . e($r['merchant']['name']), '<h2>' . e($r['merchant']['name']) . '</h2><p class="amount">' . $amount . '</p><p>' . e($r['description'] ?? '') . '</p>' . $state . $btn));
    }

    public function gift(string $code)
    {
        $e = GiftEnvelope::with('sender')->where('code', strtoupper($code))->firstOrFail();
        return response($this->layout('Cadeau FlashPay', '<div style="font-size:48px">🧧</div><h2>' . e($e->sender->full_name) . ' vous envoie un cadeau</h2><p>' . e($e->message ?? '') . '</p>'
            . ($e->status === 'active' ? '<a class="btn" href="flashpay://gift?c=' . e($e->code) . '">Ouvrir dans FlashPay</a><p class="muted">Code : <b>' . e($e->code) . '</b> — valable jusqu\'au ' . $e->expires_at->format('d/m/Y H:i') . '</p>' : '<p class="err">Ce cadeau n\'est plus disponible.</p>')));
    }

    /** Lien de demande d'argent partagé (WhatsApp, SMS…) → ouverture dans l'app. */
    public function moneyRequest(string $reference)
    {
        $r = \App\Models\MoneyRequest::with('requester:id,full_name')->where('reference', strtoupper($reference))->firstOrFail();
        $amount = number_format($r->amount, 0, ',', ' ') . ' ' . $r->currency;
        $state = $r->isOpen()
            ? '<a class="btn" href="flashpay://request?r=' . e($r->reference) . '">Payer avec FlashPay</a>'
              . '<p class="muted">Pas encore FlashPay ? Installez l\'application et inscrivez-vous avec le numéro qui a reçu cette demande : elle vous attendra dans « Demandes d\'argent ».</p>'
              . '<p class="muted">Valable jusqu\'au ' . $r->expires_at?->format('d/m/Y') . ' · Réf. ' . e($r->reference) . '</p>'
            : '<p class="err">Cette demande n\'est plus à régler (' . e(['paid' => 'déjà payée', 'declined' => 'refusée', 'cancelled' => 'annulée', 'expired' => 'expirée'][$r->status] ?? $r->status) . ').</p>';
        return response($this->layout('Demande FlashPay', '<div style="font-size:44px">💸</div><h2>' . e($r->requester->full_name) . ' vous demande</h2><p class="amount">' . $amount . '</p>'
            . ($r->note ? '<p>« ' . e($r->note) . ' »</p>' : '') . $state));
    }

    public function apiDocs()
    {
        return response($this->layout('API e-commerce FlashPay', file_get_contents(resource_path('docs/api-ecommerce.html')), 760));
    }

    // ------------------------------------------------------------ HTML

    protected function checkoutPage(PaymentIntent $pi, array $p, ?string $error = null, array $state = []): string
    {
        $amount = number_format($pi->amount, 0, ',', ' ') . ' ' . $pi->currency;
        $head = '<p class="muted">Paiement sécurisé</p><h2>' . e($pi->merchant->business_name) . '</h2><p class="amount">' . $amount . '</p>'
            . ($pi->description ? '<p>' . e($pi->description) . '</p>' : '') . ($pi->order_reference ? '<p class="muted">Commande ' . e($pi->order_reference) . '</p>' : '')
            . ($pi->environment === 'sandbox' ? '<p class="warn">MODE TEST — aucun débit réel</p>' : '');

        if ($p['status'] !== 'pending') {
            $msg = match ($p['status']) { 'confirmed' => '<p class="ok">✔ Paiement confirmé. Merci !</p>', 'partially_refunded', 'refunded' => '<p class="ok">✔ Paiement confirmé</p><p class="muted">Remboursé : ' . number_format($pi->amount_refunded, 0, ',', ' ') . ' ' . $pi->currency . '</p>', 'expired' => '<p class="err">Ce paiement a expiré.</p>', 'canceled' => '<p class="err">Paiement annulé.</p>', default => '<p class="err">Paiement ' . e($p['status']) . '. ' . e($pi->failure_reason ?? '') . '</p>' };
            $back = $pi->return_url ? '<a class="btn" href="' . e($pi->return_url . (str_contains($pi->return_url, '?') ? '&' : '?') . 'payment_intent=' . $pi->public_id . '&status=' . $p['status']) . '">Retour au site marchand</a>' : '';
            return $this->layout('Paiement FlashPay', $head . $msg . $back);
        }

        $err = $error ? '<p class="err">' . e($error) . '</p>' : '';
        $step = $state['step'] ?? 'phone';
        $form = $step === 'phone'
            ? '<input name="phone" placeholder="Numéro FlashPay (ex. 06 612 34 56)" value="' . e($state['phone'] ?? '') . '" required><input type="hidden" name="step" value="phone"><button class="btn">Recevoir un code SMS</button>'
            : '<input type="hidden" name="phone" value="' . e($state['phone'] ?? '') . '"><input type="hidden" name="step" value="otp"><input name="otp" inputmode="numeric" placeholder="Code reçu par SMS" required><input name="pin" type="password" inputmode="numeric" placeholder="Votre code PIN FlashPay" required><button class="btn">Payer ' . $amount . '</button>'
              . (! empty($state['debug']) ? '<p class="warn">Sandbox : code ' . e($state['debug']) . '</p>' : '');

        return $this->layout('Paiement FlashPay', $head . $err
            . '<a class="btn outline" href="' . e($p['qr_payload']) . '">Ouvrir l\'application FlashPay</a><div class="sep">ou payer ici</div>'
            . '<form method="post">' . $form . '</form><form method="post"><input type="hidden" name="action" value="cancel"><button class="link">Annuler et revenir au marchand</button></form>');
    }

    protected function layout(string $title, string $body, int $width = 420): string
    {
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title><style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#eef2f9;color:#0f1d3a}
.card{max-width:' . $width . 'px;margin:32px auto;background:#fff;border-radius:18px;padding:28px 24px;box-shadow:0 8px 30px rgba(15,29,58,.08);text-align:center}
.brand{font-weight:800;color:#1b4fd8;font-size:20px;margin-bottom:10px}.amount{font-size:34px;font-weight:800;margin:6px 0}
.muted{color:#6b7896;font-size:14px}.ok{color:#0f8a4b;font-weight:600}.err{color:#c62828;font-weight:600}.warn{background:#fff4d6;color:#8a5a00;padding:6px 10px;border-radius:8px;font-size:13px}
input{width:100%;box-sizing:border-box;padding:13px;border:1px solid #cdd6e6;border-radius:10px;font-size:16px;margin:6px 0}
.btn{display:block;width:100%;box-sizing:border-box;background:#1b4fd8;color:#fff;border:0;border-radius:12px;padding:14px;font-size:16px;font-weight:700;text-decoration:none;margin:10px 0;cursor:pointer}
.btn.outline{background:#fff;color:#1b4fd8;border:2px solid #1b4fd8}.link{background:none;border:0;color:#6b7896;text-decoration:underline;cursor:pointer;margin-top:8px}
.sep{color:#9aa6bf;font-size:13px;margin:8px 0}.doc{text-align:left}.doc pre{background:#0f1d3a;color:#e6ecff;padding:12px;border-radius:10px;overflow:auto;font-size:13px}
</style></head><body><div class="card"><div class="brand">⚡ FlashPay</div>' . $body . '</div></body></html>';
    }
}

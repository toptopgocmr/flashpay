<?php

namespace App\Services\Client;

use App\Exceptions\BusinessException;
use App\Models\MoneyRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use App\Services\SwitchService;
use App\Support\Audit;
use Illuminate\Support\Str;

/**
 * Demandes d'argent entre utilisateurs FlashPay (« Scanner un ami pour lui
 * demander ») : le demandeur désigne le payeur par son QR, NFC ou numéro ;
 * le payeur est notifié et règle en wallet → wallet (PIN) ou refuse.
 */
class MoneyRequestService
{
    public const TTL_DAYS = 7;
    public const MAX_PENDING = 20;

    public function __construct(
        protected NotificationService $notify,
        protected PeexFlowService $flows,
        protected PeexCorridors $corridors,
        protected SwitchService $switch,
    ) {
    }

    /** Compte FlashPay du payeur (profil client en priorité). */
    protected function payerFor(string $phone): ?User
    {
        $candidates = \App\Support\Phone::candidates($phone);
        return User::whereIn('phone', $candidates)->whereHas('roles', fn ($r) => $r->where('name', 'client'))->first()
            ?? $this->flows->findUserByPhone($phone);
    }

    public function create(User $requester, array $d): MoneyRequest
    {
        $phone = $this->corridors->resolve((string) $d['phone'])['phone'];
        if (ltrim($phone, '+') === ltrim((string) $requester->phone, '+')) {
            throw new BusinessException('Vous ne pouvez pas vous adresser une demande à vous-même.', 'self_request');
        }
        if (MoneyRequest::where('requester_id', $requester->id)->where('status', 'pending')->count() >= self::MAX_PENDING) {
            throw new BusinessException('Trop de demandes en attente. Annulez-en avant d\'en créer une nouvelle.', 'too_many_requests');
        }
        $wallet = $this->flows->walletOf($requester);
        $payer = $this->payerFor($phone);

        $req = MoneyRequest::create([
            'reference' => 'DM-' . strtoupper(Str::random(10)),
            'requester_id' => $requester->id,
            'payer_id' => $payer?->id,
            'payer_phone' => ltrim($phone, '+'),
            'amount' => (int) $d['amount'],
            'currency' => $wallet->currency,
            'note' => isset($d['note']) ? trim(preg_replace('/\s+/u', ' ', (string) $d['note'])) ?: null : null,
            'status' => 'pending',
            'channel' => $d['channel'] ?? 'app',
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);
        // « app » : notification FlashPay (SMS si l'ami n'a pas encore l'app).
        // « sms » : SMS en plus de la notification. « whatsapp » / « link » : partagé par
        // le demandeur depuis son téléphone (share_text) — notification in-app quand même.
        $this->ask($req, $requester, false, ($d['channel'] ?? 'app') === 'sms');
        Audit::log('money_request.create', $req, ['amount' => $req->amount, 'payer_phone' => $req->payer_phone], $requester->id);

        return $req->load('payer:id,full_name,phone');
    }

    protected function ask(MoneyRequest $req, User $requester, bool $reminder = false, bool $forceSms = false): void
    {
        $amount = self::money($req->amount, $req->currency);
        if ($req->payer) {
            $this->notify->toUser($req->payer, 'money_request', ($reminder ? 'Rappel : ' : '') . "{$requester->full_name} vous demande {$amount}",
                ($req->note ? "Motif : {$req->note}. " : '') . 'Ouvrez FlashPay pour payer ou refuser.',
                ['data' => ['money_request_id' => $req->id]] + ($forceSms ? ['sms' => $this->smsText($req, $requester, $reminder)] : []));
        }
        if (! $req->payer) {
            $this->notify->sms('+' . $req->payer_phone, 'FlashPay: ' . $this->smsText($req, $requester, $reminder));
        }
    }

    public static function money(int $amount, string $currency): string
    {
        return number_format($amount, 0, ',', ' ') . ' ' . $currency;
    }

    public function link(MoneyRequest $req): string
    {
        return url('/d/' . $req->reference);
    }

    /** SMS court, sans accents ni emoji (compatibilité GSM). */
    protected function smsText(MoneyRequest $req, User $requester, bool $reminder = false): string
    {
        $t = ($reminder ? 'Rappel - ' : '') . "{$requester->full_name} vous demande " . number_format($req->amount, 0, ',', ' ') . " {$req->currency}"
            . ($req->note ? " ({$req->note})" : '') . '. Payer: ' . $this->link($req) . ' Ref ' . $req->reference;
        return \Illuminate\Support\Str::ascii($t);
    }

    /** Message à partager (WhatsApp, SMS du téléphone, copier) — propre et complet. */
    public function shareText(MoneyRequest $req): string
    {
        $req->loadMissing('requester:id,full_name', 'payer:id,full_name');
        $hello = $req->payer?->full_name ? 'Bonjour ' . explode(' ', trim($req->payer->full_name))[0] . ',' : 'Bonjour,';
        return $hello . "\n\n"
            . "Je vous envoie une demande de paiement FlashPay :\n"
            . '💰 Montant : ' . self::money($req->amount, $req->currency) . "\n"
            . ($req->note ? "📝 Motif : {$req->note}\n" : '')
            . "👤 Demandé par : {$req->requester->full_name}\n"
            . '📅 Valable jusqu\'au : ' . $req->expires_at?->format('d/m/Y') . "\n\n"
            . 'Payez en un geste : ' . $this->link($req) . "\n"
            . "Réf. {$req->reference}";
    }

    /** Demande enrichie pour l'app : libellés, lien et message de partage. */
    public function present(MoneyRequest $req, ?User $viewer = null): array
    {
        $req->loadMissing('requester:id,full_name,phone', 'payer:id,full_name,phone');
        return $req->toArray() + [
            'amount_label' => self::money($req->amount, $req->currency),
            'status_label' => ['pending' => 'En attente', 'paid' => 'Payée', 'declined' => 'Refusée', 'cancelled' => 'Annulée', 'expired' => 'Expirée'][$req->status] ?? $req->status,
            'channel_label' => ['app' => 'Notification FlashPay', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'link' => 'Lien partagé'][$req->channel ?? 'app'] ?? 'Notification FlashPay',
            'payer_display' => $req->payer?->full_name ?? '+' . ltrim((string) $req->payer_phone, '+'),
            'payer_has_app' => (bool) $req->payer_id,
            'share_link' => $this->link($req),
            'share_text' => $viewer && $viewer->id === $req->requester_id ? $this->shareText($req) : null,
        ];
    }

    protected function assertPayer(User $user, MoneyRequest $req): void
    {
        $mine = $req->payer_id === $user->id || in_array($req->payer_phone, array_map(fn ($p) => ltrim($p, '+'), \App\Support\Phone::candidates($user->phone)), true);
        if (! $mine) {
            throw new BusinessException('Cette demande ne vous concerne pas.', 'forbidden', 403);
        }
    }

    public function pay(User $user, MoneyRequest $req): MoneyRequest
    {
        $this->assertPayer($user, $req);
        if (! $req->isOpen()) {
            throw new BusinessException('Cette demande n\'est plus à régler.', 'request_closed');
        }
        $from = $this->flows->walletOf($user);
        $to = $this->flows->walletOf($req->requester);
        if ($from->currency !== $to->currency) {
            throw new BusinessException('Demande entre deux devises différentes : utilisez « Envoyer de l\'argent ».', 'currency_mismatch');
        }

        $tx = $this->switch->process([
            'type' => 'p2p', 'scope' => 'national',
            'source_rail' => 'wallet', 'source_wallet_id' => $from->id,
            'destination_rail' => 'wallet', 'destination_wallet_id' => $to->id,
            'amount' => $req->amount, 'currency' => $from->currency,
            'initiated_by' => $user->id,
            'meta' => ['channel' => 'money_request', 'money_request_id' => $req->id, 'sender_name' => $user->full_name,
                'note' => $req->note, 'beneficiary_name' => $req->requester->full_name],
        ]);
        if ($tx->status !== 'successful') {
            throw new BusinessException($tx->failure_reason ?: 'Paiement de la demande impossible.', 'payment_failed');
        }
        $req->update(['status' => 'paid', 'payer_id' => $user->id, 'transaction_id' => $tx->id, 'answered_at' => now()]);
        $this->notify->toUser($req->requester, 'money_request_paid', "{$user->full_name} a payé votre demande",
            number_format($req->amount, 0, ',', ' ') . " {$req->currency} crédités sur votre wallet", ['severity' => 'success', 'sms' => false]);

        return $req->fresh(['requester:id,full_name,phone', 'payer:id,full_name,phone']);
    }

    public function decline(User $user, MoneyRequest $req): MoneyRequest
    {
        $this->assertPayer($user, $req);
        if ($req->status !== 'pending') {
            throw new BusinessException('Action impossible.', 'request_closed');
        }
        $req->update(['status' => 'declined', 'payer_id' => $user->id, 'answered_at' => now()]);
        $this->notify->toUser($req->requester, 'money_request_declined', "{$user->full_name} a refusé votre demande",
            number_format($req->amount, 0, ',', ' ') . " {$req->currency}", ['sms' => false]);

        return $req;
    }

    public function cancel(User $user, MoneyRequest $req): MoneyRequest
    {
        if ($req->requester_id !== $user->id || $req->status !== 'pending') {
            throw new BusinessException('Action impossible.', 'forbidden', 403);
        }
        $req->update(['status' => 'cancelled', 'answered_at' => now()]);

        return $req;
    }

    /** Relance (au plus une fois par heure). */
    public function remind(User $user, MoneyRequest $req): MoneyRequest
    {
        if ($req->requester_id !== $user->id || ! $req->isOpen()) {
            throw new BusinessException('Action impossible.', 'forbidden', 403);
        }
        if ($req->reminded_at && $req->reminded_at->gt(now()->subHour())) {
            throw new BusinessException('Relance déjà envoyée il y a moins d\'une heure.', 'too_soon');
        }
        $this->ask($req, $user, true, $req->channel === 'sms');
        $req->update(['reminded_at' => now()]);

        return $req;
    }

    /** Demandes reçues (à payer) et envoyées de l'utilisateur. */
    public function listFor(User $user): array
    {
        MoneyRequest::where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => 'expired']);
        $phones = array_map(fn ($p) => ltrim($p, '+'), \App\Support\Phone::candidates($user->phone));

        return [
            'incoming' => MoneyRequest::with('requester:id,full_name,phone', 'payer:id,full_name,phone')
                ->where(fn ($q) => $q->where('payer_id', $user->id)->orWhereIn('payer_phone', $phones))
                ->latest()->limit(50)->get()->map(fn ($r) => $this->present($r, $user)),
            'outgoing' => MoneyRequest::with('requester:id,full_name,phone', 'payer:id,full_name,phone')->where('requester_id', $user->id)->latest()->limit(50)->get()
                ->map(fn ($r) => $this->present($r, $user)),
        ];
    }
}

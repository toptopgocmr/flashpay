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
            'note' => $d['note'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);
        $this->ask($req, $requester);
        Audit::log('money_request.create', $req, ['amount' => $req->amount, 'payer_phone' => $req->payer_phone], $requester->id);

        return $req->load('payer:id,full_name,phone');
    }

    protected function ask(MoneyRequest $req, User $requester, bool $reminder = false): void
    {
        $amount = number_format($req->amount, 0, ',', ' ') . " {$req->currency}";
        if ($req->payer) {
            $this->notify->toUser($req->payer, 'money_request', ($reminder ? 'Rappel : ' : '') . "{$requester->full_name} vous demande {$amount}",
                $req->note ?: 'Ouvrez FlashPay pour payer ou refuser.', ['data' => ['money_request_id' => $req->id]]);
        } else {
            $this->notify->sms('+' . $req->payer_phone, "FlashPay: {$requester->full_name} vous demande {$amount}. Installez FlashPay pour payer en toute securite.");
        }
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
        $this->ask($req, $user, true);
        $req->update(['reminded_at' => now()]);

        return $req;
    }

    /** Demandes reçues (à payer) et envoyées de l'utilisateur. */
    public function listFor(User $user): array
    {
        MoneyRequest::where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => 'expired']);
        $phones = array_map(fn ($p) => ltrim($p, '+'), \App\Support\Phone::candidates($user->phone));

        return [
            'incoming' => MoneyRequest::with('requester:id,full_name,phone')
                ->where(fn ($q) => $q->where('payer_id', $user->id)->orWhereIn('payer_phone', $phones))
                ->latest()->limit(50)->get(),
            'outgoing' => MoneyRequest::with('payer:id,full_name,phone')->where('requester_id', $user->id)->latest()->limit(50)->get(),
        ];
    }
}

<?php

namespace App\Services\Client;

use App\Exceptions\BusinessException;
use App\Models\BillSplit;
use App\Models\BillSplitShare;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use App\Services\SwitchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cagnotte (§3.5.2, ex-« partage de note ») : facture partagée ou cadeau commun.
 *  - le créateur désigne le bénéficiaire (lui-même par défaut) par son numéro ;
 *  - les contributeurs invités sont notifiés (SMS s'ils n'ont pas FlashPay) ;
 *  - parts égales, parts fixées par le créateur ou montant libre (objectif facultatif) ;
 *  - chaque contribution arrive directement dans le wallet du bénéficiaire ;
 *  - le créateur suit qui a contribué, relance, ajoute des personnes, clôture
 *    et obtient un reçu récapitulatif.
 */
class SplitBillService
{
    public const MIN_FREE = 100;

    public function __construct(
        protected NotificationService $notify,
        protected PeexFlowService $flows,
        protected PeexCorridors $corridors,
        protected SwitchService $switch,
    ) {
    }

    // ------------------------------------------------------------ Création

    public function create(User $creator, array $d): BillSplit
    {
        $wallet = $this->flows->walletOf($creator);
        $purpose = $d['purpose'] ?? 'bill';
        $mode = $d['mode'];
        $beneficiary = $this->resolveBeneficiary($creator, $d['beneficiary_phone'] ?? null);
        $beneficiaryIsCreator = $beneficiary->id === $creator->id;

        $parts = $this->normalizeParticipants($d['participants'], [$creator, $beneficiary]);
        if ($parts->isEmpty()) {
            throw new BusinessException('Ajoutez au moins un contributeur (autre que vous et le bénéficiaire).', 'no_participant');
        }
        $total = (int) ($d['total_amount'] ?? 0);
        $includeSelf = (bool) ($d['include_self'] ?? true);
        $selfAmount = 0;

        if ($mode === 'equal') {
            if ($total < 100) {
                throw new BusinessException('Indiquez le montant total à partager.', 'invalid_total');
            }
            $n = $parts->count() + ($includeSelf ? 1 : 0);
            $each = intdiv($total, $n);
            $parts = $parts->map(fn ($p) => ['phone' => $p['phone'], 'amount' => $each]);
            $selfAmount = $includeSelf ? $total - $each * $parts->count() : 0;
        } elseif ($mode === 'custom') {
            $sum = (int) $parts->sum('amount');
            if ($parts->contains(fn ($p) => ! $p['amount'] || $p['amount'] <= 0)) {
                throw new BusinessException('Indiquez le montant de chaque contributeur.', 'invalid_shares');
            }
            if ($total <= 0) {
                $total = $sum; // pas de total saisi : somme des parts
            }
            if ($sum > $total) {
                throw new BusinessException('Parts fixées invalides : leur somme dépasse le total.', 'invalid_shares');
            }
            $selfAmount = $total - $sum;
            if ($selfAmount > 0 && ! $beneficiaryIsCreator && ! $includeSelf) {
                throw new BusinessException('La somme des parts doit être égale au total (ou incluez votre propre part).', 'invalid_shares');
            }
        } else { // free : chacun donne ce qu'il veut ; total = objectif (0 = sans objectif)
            $parts = $parts->map(fn ($p) => ['phone' => $p['phone'], 'amount' => 0]);
        }

        $split = DB::transaction(function () use ($creator, $beneficiary, $beneficiaryIsCreator, $wallet, $d, $purpose, $mode, $total, $parts, $selfAmount, $includeSelf) {
            $split = BillSplit::create([
                'creator_id' => $creator->id,
                'beneficiary_user_id' => $beneficiary->id,
                'beneficiary_phone' => $beneficiary->phone,
                'beneficiary_name' => $beneficiary->full_name,
                'title' => $d['title'],
                'purpose' => $purpose,
                'message' => $d['message'] ?? null,
                'total_amount' => $total,
                'currency' => $wallet->currency,
                'mode' => $mode,
                'status' => 'open',
                'deadline' => $d['deadline'] ?? null,
            ]);
            // Part du créateur : rien à payer s'il est le bénéficiaire, sinon il contribue comme les autres.
            if ($beneficiaryIsCreator && $selfAmount > 0) {
                BillSplitShare::create(['bill_split_id' => $split->id, 'user_id' => $creator->id, 'phone' => $creator->phone, 'amount' => $selfAmount, 'status' => 'self']);
            } elseif (! $beneficiaryIsCreator && $includeSelf && ($selfAmount > 0 || $mode === 'free')) {
                BillSplitShare::create(['bill_split_id' => $split->id, 'user_id' => $creator->id, 'phone' => $creator->phone, 'amount' => $selfAmount, 'status' => 'pending']);
            }
            foreach ($parts as $p) {
                $this->addShare($split, $p['phone'], (int) $p['amount'], $creator);
            }
            return $split;
        });

        // Facture : le bénéficiaire est prévenu tout de suite. Cadeau : surprise jusqu'à la clôture.
        if ($purpose === 'bill' && ! $beneficiaryIsCreator) {
            $this->notify->toUser($beneficiary, 'split_beneficiary', "{$creator->full_name} a ouvert une cagnotte en votre faveur", "« {$split->title} » : les contributions arriveront directement sur votre wallet.", ['data' => ['bill_split_id' => $split->id], 'sms' => false]);
        }

        return $split->load('shares');
    }

    /** Ajouter des contributeurs à une cagnotte ouverte. [{phone, amount?}] */
    public function addParticipants(User $creator, BillSplit $split, array $participants): BillSplit
    {
        $this->assertOpen($split);
        $split->load('shares');
        $parts = $this->normalizeParticipants($participants, [$split->creator, $split->payee()])
            ->reject(fn ($p) => $split->shares->contains(fn ($s) => $this->samePhone($s->phone, $p['phone'])))->values();
        if ($parts->isEmpty()) {
            throw new BusinessException('Ces numéros participent déjà à la cagnotte.', 'no_participant');
        }

        return DB::transaction(function () use ($creator, $split, $parts) {
            if ($split->mode === 'equal') {
                if ($split->shares()->where('status', 'paid')->exists()) {
                    throw new BusinessException('Des parts ont déjà été réglées : on ne peut plus ajouter de personne en parts égales. Créez une cagnotte à montant libre.', 'equal_locked');
                }
                // Nouvelle répartition à parts égales entre tous.
                $current = $split->shares()->whereIn('status', ['pending', 'self'])->get();
                $n = $current->count() + $parts->count();
                $each = intdiv((int) $split->total_amount, $n);
                foreach ($parts as $p) {
                    $this->addShare($split, $p['phone'], $each, $creator);
                }
                $all = $split->shares()->whereIn('status', ['pending', 'self'])->orderBy('id')->get();
                $rest = (int) $split->total_amount - $each * ($all->count() - 1);
                foreach ($all as $i => $s) {
                    $s->update(['amount' => $i === 0 ? $rest : $each]);
                }
            } elseif ($split->mode === 'custom') {
                if ($parts->contains(fn ($p) => ! $p['amount'] || $p['amount'] <= 0)) {
                    throw new BusinessException('Indiquez le montant de chaque nouveau contributeur.', 'invalid_shares');
                }
                foreach ($parts as $p) {
                    $this->addShare($split, $p['phone'], (int) $p['amount'], $creator);
                }
                $split->update(['total_amount' => (int) $split->total_amount + (int) $parts->sum('amount')]);
            } else {
                foreach ($parts as $p) {
                    $this->addShare($split, $p['phone'], 0, $creator);
                }
            }
            return $split->fresh('shares');
        });
    }

    // ------------------------------------------------------------ Paiement

    public function pay(User $user, BillSplitShare $share, ?int $amount = null): BillSplitShare
    {
        $split = $share->split;
        if ($share->status !== 'pending' || $split->status !== 'open') {
            throw new BusinessException('Cette contribution n\'est plus attendue.', 'share_closed');
        }
        if ($share->user_id !== $user->id && ! $this->samePhone($share->phone, $user->phone)) {
            throw new BusinessException('Cette contribution ne vous concerne pas.', 'forbidden', 403);
        }
        if ($split->mode === 'free') {
            $amount = (int) $amount;
            if ($amount < self::MIN_FREE) {
                throw new BusinessException('Indiquez le montant de votre contribution (100 minimum).', 'invalid_amount');
            }
        } else {
            $amount = (int) $share->amount;
        }
        $payee = $split->payee();
        if (! $payee || $payee->id === $user->id) {
            throw new BusinessException('Vous êtes le bénéficiaire de cette cagnotte.', 'forbidden', 403);
        }
        $from = $this->flows->walletOf($user);
        $to = $this->flows->walletOf($payee);

        $tx = $this->switch->process([
            'type' => 'split_payment', 'scope' => 'national',
            'source_rail' => 'wallet', 'source_wallet_id' => $from->id,
            'destination_rail' => 'wallet', 'destination_wallet_id' => $to->id,
            'amount' => $amount, 'currency' => $from->currency,
            'initiated_by' => $user->id,
            'meta' => ['channel' => 'split', 'bill_split_id' => $split->id, 'sender_name' => $user->full_name, 'note' => 'Cagnotte : ' . $split->title, 'beneficiary_name' => $payee->full_name],
        ]);
        if ($tx->status !== 'successful') {
            throw new BusinessException($tx->failure_reason ?: 'Paiement de la contribution impossible.', 'payment_failed');
        }
        $share->update(['status' => 'paid', 'amount' => $amount, 'paid_at' => now(), 'user_id' => $user->id, 'transaction_id' => $tx->id]);

        $split->refresh();
        $fmt = $this->money($amount, $split->currency);
        if ($split->creator_id !== $user->id) {
            $this->notify->toUser($split->creator, 'split_contribution', "{$user->full_name} a contribué {$fmt}", "Cagnotte « {$split->title} »", ['data' => ['bill_split_id' => $split->id], 'sms' => false]);
        }

        if ($split->mode !== 'free' && ! $split->shares()->where('status', 'pending')->exists()) {
            $this->finish($split, 'settled');
        } elseif ($split->mode === 'free' && $split->total_amount > 0) {
            $paid = (int) $split->shares()->where('status', 'paid')->sum('amount');
            if ($paid >= $split->total_amount && $paid - $amount < $split->total_amount) {
                $this->notify->toUser($split->creator, 'split_target', "Objectif atteint : « {$split->title} »", $this->money($paid, $split->currency) . ' collectés. Vous pouvez clôturer la cagnotte.', ['severity' => 'success', 'sms' => false, 'data' => ['bill_split_id' => $split->id]]);
            }
        }
        return $share->fresh();
    }

    public function decline(User $user, BillSplitShare $share): BillSplitShare
    {
        if ($share->status !== 'pending' || ($share->user_id !== $user->id && ! $this->samePhone($share->phone, $user->phone))) {
            throw new BusinessException('Action impossible.', 'forbidden', 403);
        }
        $share->update(['status' => 'declined']);
        $split = $share->split;
        if ($split->creator_id !== $user->id) {
            $this->notify->toUser($split->creator, 'split_declined', "{$user->full_name} ne participera pas", "Cagnotte « {$split->title} »", ['sms' => false, 'data' => ['bill_split_id' => $split->id]]);
        }
        if ($split->mode !== 'free' && $split->status === 'open' && ! $split->shares()->where('status', 'pending')->exists()) {
            $this->finish($split->fresh(), 'settled');
        }
        return $share;
    }

    // ------------------------------------------------------------ Suivi

    /** Relance des contributions en attente (au plus une fois par heure et par personne). */
    public function remind(BillSplit $split): int
    {
        $n = 0;
        foreach ($split->shares()->where('status', 'pending')->get() as $share) {
            if ($share->user_id === $split->creator_id) {
                continue;
            }
            if ($share->reminded_at && $share->reminded_at->gt(now()->subHour())) {
                continue;
            }
            $this->request($split, $share, $split->creator, true);
            $share->update(['reminded_at' => now()]);
            $n++;
        }
        return $n;
    }

    /** Le créateur clôture la cagnotte : plus de contribution possible, bénéficiaire prévenu. */
    public function close(BillSplit $split): BillSplit
    {
        $this->assertOpen($split);
        $this->finish($split, 'closed');
        return $split->fresh('shares');
    }

    /** Annulation : seulement si personne n'a encore contribué (l'argent est déjà chez le bénéficiaire sinon). */
    public function cancel(BillSplit $split): BillSplit
    {
        $this->assertOpen($split);
        if ($split->shares()->where('status', 'paid')->exists()) {
            throw new BusinessException('Des contributions ont déjà été versées au bénéficiaire : clôturez la cagnotte plutôt que de l\'annuler.', 'has_contributions');
        }
        $split->update(['status' => 'cancelled', 'closed_at' => now()]);
        $split->shares()->where('status', 'pending')->update(['status' => 'declined']);
        foreach ($split->shares()->whereNotNull('user_id')->where('user_id', '!=', $split->creator_id)->with('user')->get() as $s) {
            $this->notify->toUser($s->user, 'split_cancelled', "Cagnotte « {$split->title} » annulée", 'Aucune contribution n\'est plus attendue.', ['sms' => false]);
        }
        return $split->fresh('shares');
    }

    public function summary(BillSplit $s, ?User $viewer = null): array
    {
        $s->loadMissing('shares.user:id,full_name', 'creator:id,full_name,phone');
        $shares = $s->shares->where('status', '!=', 'self');
        $paid = (int) $s->shares->where('status', 'paid')->sum('amount');
        $target = (int) $s->total_amount;
        return array_merge($s->toArray(), [
            'purpose_label' => BillSplit::PURPOSES[$s->purpose ?? 'bill'] ?? 'Cagnotte',
            'mode_label' => BillSplit::MODES[$s->mode] ?? $s->mode,
            'status_label' => BillSplit::STATUSES[$s->status] ?? $s->status,
            'beneficiary' => [
                'name' => $s->beneficiary_name ?? $s->creator?->full_name,
                'phone' => $s->beneficiary_phone ?? $s->creator?->phone,
                'is_creator' => ! $s->beneficiary_user_id || $s->beneficiary_user_id === $s->creator_id,
            ],
            'paid_amount' => $paid,
            'pending_amount' => (int) $s->shares->where('status', 'pending')->sum('amount'),
            'self_amount' => (int) $s->shares->where('status', 'self')->sum('amount'),
            'target_amount' => $target,
            'progress' => $target > 0 ? min(1, round($paid / $target, 4)) : null,
            'contributors_total' => $shares->count(),
            'contributors_paid' => $shares->where('status', 'paid')->count(),
            'contributors_declined' => $shares->where('status', 'declined')->count(),
            'is_creator' => $viewer ? $viewer->id === $s->creator_id : null,
            'can_cancel' => $s->status === 'open' && $paid === 0,
        ]);
    }

    /** Données du reçu récapitulatif (page web imprimable / PDF). */
    public function receipt(BillSplit $s): array
    {
        $sum = $this->summary($s);
        $s->loadMissing('shares.user:id,full_name');
        $tz = 'Africa/Brazzaville';
        $rows = $s->shares->where('status', '!=', 'self')->sortBy(fn ($x) => [$x->status === 'paid' ? 0 : 1, $x->paid_at?->timestamp ?? PHP_INT_MAX])
            ->map(fn ($x) => [
                'name' => $x->user?->full_name ?? ('+' . ltrim($x->phone, '+')),
                'phone' => '+' . ltrim($x->phone, '+'),
                'amount' => (int) $x->amount,
                'status' => $x->status,
                'status_label' => ['paid' => 'Payé', 'pending' => 'En attente', 'declined' => 'Refusé', 'expired' => 'Non versé'][$x->status] ?? $x->status,
                'paid_at' => $x->paid_at?->timezone($tz)->format('d/m/Y H:i'),
            ])->values()->all();

        return $sum + [
            'rows' => $rows,
            'created' => $s->created_at?->timezone($tz)->format('d/m/Y à H:i'),
            'closed' => $s->closed_at?->timezone($tz)->format('d/m/Y à H:i'),
            'reference' => 'CAG-' . str_pad((string) $s->id, 6, '0', STR_PAD_LEFT),
        ];
    }

    /** Texte récapitulatif (partage WhatsApp / SMS). */
    public function receiptText(BillSplit $s): string
    {
        $r = $this->receipt($s);
        $lines = ["🧾 Cagnotte FlashPay « {$s->title} » ({$r['purpose_label']})", 'Bénéficiaire : ' . $r['beneficiary']['name'], ''];
        foreach ($r['rows'] as $row) {
            $lines[] = ($row['status'] === 'paid' ? '✅ ' : '⏳ ') . $row['name'] . ' : ' . ($row['amount'] > 0 ? $this->money($row['amount'], $s->currency) : '—') . ($row['status'] === 'paid' ? '' : ' (' . mb_strtolower($row['status_label']) . ')');
        }
        $lines[] = '';
        $lines[] = 'Total collecté : ' . $this->money($r['paid_amount'], $s->currency) . ($r['target_amount'] > 0 ? ' / ' . $this->money($r['target_amount'], $s->currency) : '');
        $lines[] = "{$r['contributors_paid']}/{$r['contributors_total']} contributeur(s) · Réf. {$r['reference']}";
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------ Outils

    protected function resolveBeneficiary(User $creator, ?string $phone): User
    {
        if (! $phone || trim($phone) === '') {
            return $creator;
        }
        $resolved = $this->corridors->resolve($phone)['phone'];
        if ($this->samePhone($resolved, $creator->phone)) {
            return $creator;
        }
        $u = $this->flows->findUserByPhone($resolved);
        if (! $u || ($u->status ?? 'active') !== 'active') {
            throw new BusinessException('Le bénéficiaire doit avoir un compte FlashPay actif : invitez-le à installer l\'application.', 'beneficiary_not_found');
        }
        return $u;
    }

    /** Numéros normalisés, sans doublon, sans le créateur ni le bénéficiaire. */
    protected function normalizeParticipants(array $list, array $exclude): Collection
    {
        return collect($list)->map(fn ($p) => [
            'phone' => $this->corridors->resolve((string) $p['phone'])['phone'],
            'amount' => isset($p['amount']) ? (int) $p['amount'] : null,
        ])->unique('phone')
            ->reject(fn ($p) => collect($exclude)->filter()->contains(fn ($u) => $this->samePhone($p['phone'], $u->phone)))
            ->values();
    }

    protected function addShare(BillSplit $split, string $phone, int $amount, User $creator): BillSplitShare
    {
        $u = $this->flows->findUserByPhone($phone);
        $share = BillSplitShare::create(['bill_split_id' => $split->id, 'user_id' => $u?->id, 'phone' => $phone, 'amount' => $amount, 'status' => 'pending']);
        $share->setRelation('user', $u);
        $this->request($split, $share, $creator);
        return $share;
    }

    protected function request(BillSplit $split, BillSplitShare $share, User $creator, bool $reminder = false): void
    {
        $prefix = $reminder ? 'Rappel : ' : '';
        $for = $split->beneficiary_name && $split->beneficiary_user_id !== $creator->id ? " pour {$split->beneficiary_name}" : '';
        $amount = $split->mode === 'free' ? null : $this->money((int) $share->amount, $split->currency);
        $what = ($split->purpose ?? 'bill') === 'gift' ? 'au cadeau commun' : 'à la cagnotte';
        $title = $amount
            ? "{$prefix}{$creator->full_name} vous demande {$amount}"
            : "{$prefix}{$creator->full_name} vous invite à contribuer";
        $body = "Participez {$what} « {$split->title} »{$for}." . ($split->message ? " {$split->message}" : '');

        if ($share->user) {
            $this->notify->toUser($share->user, 'split_request', $title, $body, ['data' => ['bill_split_id' => $split->id, 'share_id' => $share->id]]);
        } else {
            $this->notify->sms($share->phone, "FlashPay: {$creator->full_name} vous invite a contribuer " . ($amount ? "({$amount}) " : '') . "a « {$split->title} »{$for}. Installez FlashPay pour participer.");
        }
    }

    /** Fin de la cagnotte (complète ou clôturée) : statut, notifications, reçu disponible. */
    protected function finish(BillSplit $split, string $status): void
    {
        $split->update(['status' => $status, 'closed_at' => now()]);
        $split->shares()->where('status', 'pending')->update(['status' => 'expired']);
        $paid = (int) $split->shares()->where('status', 'paid')->sum('amount');
        $count = $split->shares()->where('status', 'paid')->count();
        $total = $this->money($paid, $split->currency);

        $this->notify->toUser($split->creator, 'split_settled', ($status === 'settled' ? 'Cagnotte complète' : 'Cagnotte clôturée') . " : « {$split->title} »", "{$total} versés par {$count} contributeur(s). Le reçu est disponible.", ['severity' => 'success', 'sms' => false, 'data' => ['bill_split_id' => $split->id]]);
        $payee = $split->payee();
        if ($payee && $payee->id !== $split->creator_id) {
            $title = ($split->purpose ?? 'bill') === 'gift' ? "🎁 Vous avez reçu un cadeau commun : « {$split->title} »" : "Cagnotte « {$split->title} » terminée";
            $this->notify->toUser($payee, 'split_beneficiary', $title, "{$total} de la part de {$count} personne(s), organisé par {$split->creator->full_name}." . ($split->message ? " « {$split->message} »" : ''), ['severity' => 'success', 'sms' => false]);
        }
    }

    protected function assertOpen(BillSplit $split): void
    {
        if ($split->status !== 'open') {
            throw new BusinessException('Cette cagnotte est terminée.', 'split_closed');
        }
    }

    protected function samePhone(?string $a, ?string $b): bool
    {
        return $a && $b && ltrim($a, '+') === ltrim($b, '+');
    }

    protected function money(int $n, string $cur): string
    {
        return number_format($n, 0, ',', ' ') . " {$cur}";
    }
}

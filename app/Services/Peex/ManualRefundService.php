<?php

namespace App\Services\Peex;

use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Connectors\PeexConnector;
use App\Services\Notifications\NotificationService;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Remboursement manuel d'une opération depuis la console, envoyé par PEEX :
 *  - vers un numéro mobile money (disbursement / remittance selon le corridor) ;
 *  - vers un compte bancaire (Remittance › clients/request_bank_payment).
 *
 * L'argent part du compte PEEX de FlashPay : aucun wallet n'est débité ici.
 * Chaque demande est une ligne peex_requests (track_id …-M1, -M2…) suivie par
 * peex:sync / callbacks ; elle ne modifie pas la transaction d'origine.
 */
class ManualRefundService
{
    public function __construct(protected PeexConnector $connector, protected NotificationService $notifier)
    {
    }

    /** Requêtes de remboursement manuel d'une transaction. */
    public static function requestsOf(Transaction $tx)
    {
        return PeexRequest::where('transaction_id', $tx->id)
            ->where('track_id', 'like', $tx->reference . '-M%')
            ->latest()->get();
    }

    /** Montant encore remboursable (montant + frais − remboursements réussis ou en cours). */
    public static function refundableOf(Transaction $tx): int
    {
        $base = (int) $tx->amount + (int) $tx->fee;
        $used = $tx->relationLoaded('peexRequests')
            ? $tx->peexRequests->filter(fn ($r) => str_starts_with((string) $r->track_id, $tx->reference . '-M') && PeexRequest::normalize($r->status) !== 'failed')
                ->sum(fn ($r) => (int) ($r->amount ?? 0))
            : (int) PeexRequest::where('transaction_id', $tx->id)->where('track_id', 'like', $tx->reference . '-M%')
                ->whereNotIn('status', PeexRequest::FAILED_STATUSES)->sum('amount');

        return max(0, $base - $used);
    }

    public function refund(Transaction $tx, array $d, User $admin): array
    {
        return Cache::lock('peex:manual-refund:' . $tx->id, 30)->block(10, function () use ($tx, $d, $admin) {
            $amount = (int) $d['amount'];
            $left = self::refundableOf($tx);
            if ($amount < 1 || $amount > $left) {
                throw ValidationException::withMessages(['amount' => "Montant maximum remboursable : {$left} {$tx->currency}."]);
            }
            $currency = strtoupper($d['currency'] ?? $tx->currency ?? 'XAF');

            if ($d['channel'] === 'mobile') {
                $res = $this->connector->manualMobileRefund($tx, $d['phone'], $amount, $currency, [
                    'beneficiary' => $d['beneficiary_name'],
                    'sender' => 'FlashPay Remboursement',
                    'sender_phone' => $d['phone'],
                    'country_hint' => $d['country'] ?? null,
                    'purpose' => $d['purpose'] ?? 'Remboursement',
                    'fund_origin' => $d['fund_origin'] ?? 'Remboursement',
                ]);
            } else {
                $res = $this->connector->manualBankRefund($tx, $d, $amount, $currency);
            }

            $req = PeexRequest::where('track_id', $res['external_ref'])->first();
            Audit::log('peex.manual_refund', $tx, [
                'channel' => $d['channel'], 'amount' => $amount, 'currency' => $currency,
                'track_id' => $res['external_ref'], 'status' => $res['status'], 'reason' => $d['reason'] ?? null,
                'beneficiary' => $d['beneficiary_name'] ?? null,
                'account' => $d['channel'] === 'mobile' ? ($d['phone'] ?? null) : $this->mask($d['bank_iban'] ?? ''),
            ], $admin->id);

            if ($req) {
                $req->forceFill(['message' => trim(($req->message ? $req->message . ' · ' : '') . 'Motif : ' . ($d['reason'] ?? '—'))])->save();
            }

            return ['status' => $res['status'], 'track_id' => $res['external_ref'], 'error' => $res['raw']['error'] ?? null, 'request' => $req ? $this->present($req) : null];
        });
    }

    /** Appelé par PeexStatusHandler quand PEEX a tranché. */
    public function finalized(PeexRequest $req, bool $ok): void
    {
        $tx = $req->transaction;
        Audit::log($ok ? 'peex.manual_refund.paid' : 'peex.manual_refund.failed', $tx, ['track_id' => $req->track_id, 'status' => $req->status]);
        $this->notifier->toAdmins(
            'manual_refund',
            $ok ? 'Remboursement PEEX effectué' : 'Remboursement PEEX échoué',
            "{$req->track_id} · {$req->amount} {$req->currency}" . ($ok ? '' : ' · ' . ($req->message ?? $req->status)),
            ['severity' => $ok ? 'success' : 'critical', 'data' => ['transaction_id' => $tx?->id, 'track_id' => $req->track_id]]
        );
    }

    public function present(PeexRequest $r): array
    {
        $p = $r->request_payload ?? [];
        $bank = ($p['transaction_type'] ?? null) === 'bank';

        return [
            'id' => $r->id,
            'track_id' => $r->track_id,
            'channel' => $bank ? 'bank' : 'mobile',
            'service' => $r->service,
            'amount' => (int) $r->amount,
            'currency' => $r->currency,
            'status' => PeexRequest::normalize($r->status),
            'peex_status' => $r->status,
            'beneficiary' => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
            'account' => $bank ? trim(($p['bank_name'] ?? '') . ' · ' . $this->mask($p['bank_iban'] ?? '')) : $r->phone,
            'message' => $r->message,
            'payment_proof' => $r->payment_proof,
            'created_at' => $r->created_at?->toIso8601String(),
            'finalized_at' => $r->finalized_at?->toIso8601String(),
        ];
    }

    protected function mask(string $iban): string
    {
        $iban = preg_replace('/\s+/', '', $iban);
        return strlen($iban) > 8 ? substr($iban, 0, 4) . str_repeat('•', max(0, strlen($iban) - 8)) . substr($iban, -4) : $iban;
    }
}

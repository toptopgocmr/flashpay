<?php

use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opérations remboursées AVANT l'opération « Remboursement » distincte (…-RMB) :
 * on crée cette opération pour qu'elle apparaisse dans les journaux (app, console),
 * et on y rattache les écritures « Remboursement » du grand livre déjà passées.
 * Aucune écriture n'est créée ni supprimée : les soldes restent identiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Transaction::where('status', 'reversed')->whereNotIn('type', ['refund', 'gift_refund'])->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $tx) {
                    $m = $tx->meta ?? [];
                    if (! empty($m['refund_transaction']) || Transaction::where('reference', $tx->reference . '-RMB')->exists()) {
                        continue;
                    }
                    $entries = LedgerEntry::where('transaction_id', $tx->id)->where('memo', 'like', 'Remboursement%')->get();
                    $to = (string) ($m['outcome']['refund']['to'] ?? $m['refunded_to'] ?? '');
                    // Remboursé sur un wallet : celui crédité par les écritures « Remboursement »
                    $credit = $entries->first(fn ($e) => $e->type === 'credit' && str_starts_with($e->account, 'wallet:'));
                    $walletId = match (true) {
                        (bool) $credit => (int) substr($credit->account, 7),
                        str_starts_with($to, 'wallet:') => (int) substr($to, 7),
                        $to === 'wallet' => $tx->source_rail === 'wallet' ? $tx->source_wallet_id : Wallet::where('user_id', $tx->initiated_by)->orderBy('id')->value('id'),
                        default => null,
                    };
                    $toWallet = $walletId !== null;
                    $at = $tx->updated_at ?? now();

                    DB::transaction(function () use ($tx, $m, $entries, $toWallet, $walletId, $at) {
                        $refund = new Transaction([
                            'reference' => $tx->reference . '-RMB', 'type' => 'refund', 'scope' => $tx->scope ?: 'national',
                            'source_rail' => 'treasury', 'destination_rail' => $toWallet ? 'wallet' : (string) $tx->source_rail,
                            'destination_account' => $toWallet ? null : $tx->source_account, 'destination_wallet_id' => $toWallet ? $walletId : null,
                            'amount' => (int) $tx->amount + (int) $tx->fee, 'fee' => 0, 'currency' => $tx->currency,
                            'status' => 'successful', 'completed_at' => $at, 'initiated_by' => $tx->initiated_by,
                            'meta' => array_filter([
                                'channel' => 'auto_refund', 'refund_of' => $tx->reference, 'refund_of_id' => $tx->id, 'backfilled' => true,
                                'refunded_via' => $toWallet ? 'OfficePay' : ($m['outcome']['refund']['partner'] ?? null),
                                'sender_name' => 'OfficePay (remboursement)',
                                'beneficiary_name' => $m['payer_verified_name'] ?? $m['payer_name'] ?? $m['sender_name'] ?? null,
                                'destination_country' => $m['source_country'] ?? null, 'destination_operator' => $m['source_operator'] ?? null,
                            ], fn ($v) => $v !== null && $v !== ''),
                        ]);
                        $refund->created_at = $at;
                        $refund->updated_at = $at;
                        $refund->saveQuietly(); // pas de notification « paiement » pour une reprise d'historique
                        if ($entries->isNotEmpty()) {
                            LedgerEntry::whereIn('id', $entries->pluck('id'))->update(['transaction_id' => $refund->id]);
                        }
                        $tx->forceFill(['meta' => array_merge($m, ['refund_transaction' => $refund->reference])])->saveQuietly();
                    });
                }
            });
    }

    public function down(): void
    {
        // Reprise d'historique : pas de retour arrière automatique
    }
};

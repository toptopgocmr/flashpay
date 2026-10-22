<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Support\TransactionPresenter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Journal : solde restant après chaque opération ; détail : sort de l'argent d'une opération échouée. */
class JournalBalanceRefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    protected function tx(User $u, array $a, int $walletDelta, string $when): Transaction
    {
        $t = Transaction::create($a + ['reference' => 'FP-' . Str::upper(Str::random(12)), 'fee' => 0, 'currency' => 'XAF', 'status' => 'successful', 'initiated_by' => $u->id]);
        $t->forceFill(['created_at' => $when])->save();
        $w = $u->wallet;
        if ($walletDelta > 0) {
            app(LedgerService::class)->recordDoubleEntry($t, 'peex:x', "wallet:{$w->id}", $walletDelta);
        } elseif ($walletDelta < 0) {
            app(LedgerService::class)->recordDoubleEntry($t, "wallet:{$w->id}", 'flashpay:suspense', -$walletDelta);
        }
        $w->increment('balance', $walletDelta);
        return $t;
    }

    public function test_balance_after_each_operation_and_refund_explanations(): void
    {
        $u = User::create(['full_name' => 'Basile', 'phone' => '242067601919', 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        $w = Wallet::create(['user_id' => $u->id, 'balance' => 1000, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        $u = $u->fresh('wallet');

        $dep = $this->tx($u, ['type' => 'cash_in', 'source_rail' => 'peex', 'destination_rail' => 'wallet', 'destination_wallet_id' => $w->id, 'amount' => 5000], 5000, '2026-10-10 10:00:00');
        $send = $this->tx($u, ['type' => 'p2p', 'source_rail' => 'wallet', 'destination_rail' => 'peex', 'source_wallet_id' => $w->id, 'amount' => 1500], -1500, '2026-10-11 10:00:00');
        $failed = $this->tx($u, ['type' => 'cash_in', 'source_rail' => 'peex', 'destination_rail' => 'wallet', 'destination_wallet_id' => $w->id, 'amount' => 2000,
            'status' => 'failed', 'failure_reason' => 'Collecte refusée'], 0, '2026-10-12 10:00:00');
        // Débit du wallet puis remboursement : solde inchangé
        $rev = $this->tx($u, ['type' => 'p2p', 'source_rail' => 'wallet', 'destination_rail' => 'peex', 'source_wallet_id' => $w->id, 'amount' => 700,
            'status' => 'reversed', 'meta' => ['outcome' => ['debit' => ['done' => true], 'credit' => ['done' => false], 'refund' => ['done' => true, 'to' => 'wallet', 'at' => '2026-10-13T11:00:00+01:00']]]], 0, '2026-10-13 10:00:00');

        $this->actingAs($u, 'sanctum');
        $rows = collect($this->getJson('/api/transactions')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(4500, $rows[$rev->id]['balance_after']);    // solde actuel (1000 + 5000 − 1500)
        $this->assertSame(4500, $rows[$failed->id]['balance_after']);
        $this->assertSame(4500, $rows[$send->id]['balance_after']);
        $this->assertSame(6000, $rows[$dep->id]['balance_after']);
        $this->assertSame('XAF', $rows[$dep->id]['balance_currency']);

        $line = fn (Transaction $t) => collect(TransactionPresenter::details($t->fresh()))->firstWhere('label', 'Remboursement')['value'] ?? null;
        $this->assertSame('Aucun montant prélevé : rien à rembourser', $line($failed));
        $this->assertStringStartsWith('Remboursé : 700 XAF (montant + frais) sur votre wallet OfficePay le 13/10/2026', $line($rev));
        $this->assertNull($line($send));
    }
}

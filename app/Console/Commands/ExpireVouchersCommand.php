<?php

namespace App\Console\Commands;

use App\Services\CashNetworkService;
use Illuminate\Console\Command;

class ExpireVouchersCommand extends Command
{
    protected $signature = 'vouchers:expire';

    protected $description = 'Rembourse les bons de retrait expirés et annule les paiements carte abandonnés';

    public function handle(CashNetworkService $cash): int
    {
        $failed = false;
        $run = function (callable $fn, string $what) use (&$failed) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $failed = true;
                \Illuminate\Support\Facades\Log::error('vouchers:expire : ' . $what, ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
                $this->error($what . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
                return 'ERREUR';
            }
        };
        $n = $run(fn () => $cash->expireVouchers(), 'bons de retrait');
        $c = $run(fn () => app(\App\Services\Payments\CardPaymentService::class)->expireAbandoned(), 'paiements carte');
        $this->info("{$n} bon(s) expiré(s) remboursé(s), {$c} paiement(s) carte abandonné(s) annulé(s).");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

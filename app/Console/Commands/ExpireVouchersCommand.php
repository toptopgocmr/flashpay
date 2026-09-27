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
        $n = $cash->expireVouchers();
        $c = app(\App\Services\Payments\CardPaymentService::class)->expireAbandoned();
        $this->info("{$n} bon(s) expiré(s) remboursé(s), {$c} paiement(s) carte abandonné(s) annulé(s).");
        return self::SUCCESS;
    }
}

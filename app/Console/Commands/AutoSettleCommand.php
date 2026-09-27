<?php

namespace App\Console\Commands;

use App\Services\SettlementService;
use Illuminate\Console\Command;

class AutoSettleCommand extends Command
{
    protected $signature = 'merchants:settle';

    protected $description = 'Règlement automatique des marchands (quotidien / hebdomadaire) vers leur compte par défaut';

    public function handle(SettlementService $s): int
    {
        $done = $s->runAuto();
        foreach ($done as $d) {
            $this->line("{$d['merchant']} : {$d['amount']} ({$d['reference']})");
        }
        $this->info(count($done) . ' règlement(s) automatique(s).');
        return self::SUCCESS;
    }
}

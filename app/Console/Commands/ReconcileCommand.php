<?php

namespace App\Console\Commands;

use App\Services\Ops\ReconciliationService;
use Illuminate\Console\Command;

class ReconcileCommand extends Command
{
    protected $signature = 'ledger:reconcile {--daily-reports : Envoie aussi les rapprochements agents / marchands du jour}';

    protected $description = 'Réconciliation ledger / wallets / PEEX et alerte en cas d\'anomalie (§13.1)';

    public function handle(ReconciliationService $service): int
    {
        $r = $service->run();
        $this->info("Rapport #{$r->id} : {$r->anomalies} anomalie(s).");
        if ($this->option('daily-reports')) {
            $d = $service->dailyReports();
            $this->info("Rapprochements envoyés : {$d['agents']} agent(s), {$d['merchants']} marchand(s).");
        }
        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\PeexRequest;
use App\Services\Peex\PeexStatusHandler;
use Illuminate\Console\Command;

/**
 * Polling des demandes PEEX en attente (new / pending) — indispensable en
 * local, où PEEX ne peut pas appeler nos callbacks. Planifiée chaque minute
 * (routes/console.php) : lancer `php artisan schedule:work`.
 */
class PeexSyncCommand extends Command
{
    protected $signature = 'peex:sync {--limit=50}';

    protected $description = 'Met à jour les demandes PEEX en attente et fait avancer les transactions liées';

    public function handle(PeexStatusHandler $handler): int
    {
        $pending = PeexRequest::whereNull('finalized_at')
            ->whereIn('status', PeexRequest::PENDING_STATUSES)
            ->where('created_at', '>=', now()->subDays(3)) // PEEX n'expose que 3 jours d'historique
            ->orderBy('last_checked_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($pending as $req) {
            $before = $req->status;
            $req = $handler->refresh($req);
            if ($req->status !== $before) {
                $this->info("{$req->track_id} : {$before} → {$req->status}");
            }
        }

        $this->line("{$pending->count()} demande(s) PEEX vérifiée(s).");
        return self::SUCCESS;
    }
}

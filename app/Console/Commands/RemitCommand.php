<?php

namespace App\Console\Commands;

use App\Models\RemitRequest;
use App\Services\Remit\RemitStatusHandler;
use App\Services\Remit\TerrapayClient;
use App\Services\Remit\WacepayRemitClient;
use Illuminate\Console\Command;

/**
 *   php artisan remit:sync              statuts des transferts internationaux en attente (planifié chaque minute)
 *   php artisan remit:sync test         test des accès WacePay (login + solde) et TerraPay
 *   php artisan remit:sync test --country=CI   + services WacePay du pays
 */
class RemitCommand extends Command
{
    protected $signature = 'remit:sync {action=sync : sync | test} {--country= : pays (ISO2) dont lister les services WacePay} {--limit=40}';

    protected $description = 'Transferts internationaux (WacePay API v2 / TerraPay) : suivi des statuts et test de connexion';

    public function handle(RemitStatusHandler $handler, WacepayRemitClient $wace, TerrapayClient $terra): int
    {
        if ($this->argument('action') === 'test') {
            if ($wace->enabled()) {
                try {
                    $wace->token(fresh: true);
                    $b = $wace->balance();
                    $this->info('✔ WacePay remittance OK (' . ($wace->sandbox() ? 'sandbox' : 'production') . ") — solde : {$b['balance']} {$b['currency']}");
                    if ($c = $this->option('country')) {
                        $cur = \App\Services\Remit\RemitCountries::get($c)['currency'] ?? 'XAF';
                        $this->line(json_encode($wace->payoutServices(strtoupper($c), $cur), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                } catch (\Throwable $e) {
                    $this->error('✘ WacePay : ' . $e->getMessage());
                }
            } else {
                $this->warn('WacePay remittance désactivé (WACEPAY_REMIT_ENABLED, WACEPAY_REMIT_EMAIL, WACEPAY_REMIT_PASSWORD).');
            }
            if ($terra->enabled()) {
                $this->info('TerraPay configuré (' . ($terra->sandbox() ? 'UAT' : 'production') . ') — testez un devis depuis l\'app ou la console.');
            } else {
                $this->warn('TerraPay désactivé (TERRAPAY_ENABLED, TERRAPAY_USERNAME, TERRAPAY_PASSWORD).');
            }
            return self::SUCCESS;
        }

        $pending = RemitRequest::whereNull('finalized_at')->where('created_at', '>=', now()->subDays(10))
            ->orderBy('last_checked_at')->limit((int) $this->option('limit'))->get();
        foreach ($pending as $req) {
            $before = $req->status;
            try {
                $req = $handler->refresh($req);
            } catch (\Throwable $e) {
                $this->warn("{$req->reference} : " . $e->getMessage());
                continue;
            }
            if ($req->status !== $before) {
                $this->info("{$req->reference} ({$req->partner}) : {$before} → {$req->status}");
            }
        }
        if ($pending->count()) {
            $this->line("{$pending->count()} transfert(s) international(aux) vérifié(s).");
        }
        return self::SUCCESS;
    }
}

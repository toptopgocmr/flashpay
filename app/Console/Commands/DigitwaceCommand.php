<?php

namespace App\Console\Commands;

use App\Models\DigitwaceRequest;
use App\Services\Digitwace\DigitwaceClient;
use App\Services\Digitwace\DigitwaceStatusHandler;
use Illuminate\Console\Command;

/**
 *   php artisan digitwace:sync         statuts des versements WacePay en attente (planifié chaque minute)
 *   php artisan digitwace:sync test    test de connexion : login, URL du webhook
 *   php artisan digitwace:sync test --payers=CG   liste des payerCode d'un pays
 */
class DigitwaceCommand extends Command
{
    protected $signature = 'digitwace:sync {action=sync : sync | test} {--payers= : pays (ISO2) dont lister les payerCode} {--limit=30}';

    protected $description = 'Digitwace / WacePay : synchronisation des versements et test de connexion';

    public function handle(DigitwaceClient $client, DigitwaceStatusHandler $handler): int
    {
        if (! $client->enabled()) {
            $this->warn('Digitwace désactivé : renseignez DIGITWACE_ENABLED=true, DIGITWACE_PUBLIC_KEY et DIGITWACE_PRIVATE_KEY.');
            return self::SUCCESS;
        }

        if ($this->argument('action') === 'test') {
            $client->token(fresh: true);
            $this->info('✔ Connexion WacePay OK (jeton obtenu et mis en cache).');
            $this->line('Webhook à déclarer chez WacePay : ' . $client->callbackUrl());
            if ($c = $this->option('payers')) {
                $this->line(json_encode($client->payerCodes(strtoupper($c)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            return self::SUCCESS;
        }

        $pending = DigitwaceRequest::whereNull('finalized_at')->where('created_at', '>=', now()->subDays(7))
            ->orderBy('last_checked_at')->limit((int) $this->option('limit'))->get();
        foreach ($pending as $req) {
            $before = $req->status;
            $req = $handler->refresh($req);
            if ($req->status !== $before) {
                $this->info("{$req->reference} : {$before} → {$req->status}");
            }
        }
        $this->line("{$pending->count()} versement(s) WacePay vérifié(s).");

        return self::SUCCESS;
    }
}

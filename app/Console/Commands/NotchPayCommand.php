<?php

namespace App\Console\Commands;

use App\Models\NotchPayRequest;
use App\Services\NotchPay\NotchPayClient;
use App\Services\NotchPay\NotchPayStatusHandler;
use Illuminate\Console\Command;

/**
 *   php artisan notchpay:sync            statuts des collectes / versements Notch Pay en attente (planifié chaque minute)
 *   php artisan notchpay:sync test       test des clés : solde + URL du webhook
 *   php artisan notchpay:sync channels   synchronise les canaux Notch Pay (planifié chaque jour)
 */
class NotchPayCommand extends Command
{
    protected $signature = 'notchpay:sync {action=sync : sync | test | channels} {--limit=30}';

    protected $description = 'Notch Pay : synchronisation des statuts, des canaux et test de connexion';

    public function handle(NotchPayClient $client, NotchPayStatusHandler $handler): int
    {
        if (! $client->enabled()) {
            $this->warn('Notch Pay désactivé : renseignez NOTCHPAY_ENABLED=true, NOTCHPAY_PUBLIC_KEY, NOTCHPAY_PRIVATE_KEY et NOTCHPAY_HASH_KEY.');
            return self::SUCCESS;
        }

        if ($this->argument('action') === 'channels') {
            $c = $client->syncChannels();
            $this->info('Canaux Notch Pay : ' . collect($c)->map(fn ($l, $iso) => "{$iso} (" . implode(', ', $l) . ')')->implode(' · '));
            return self::SUCCESS;
        }

        if ($this->argument('action') === 'test') {
            $b = $client->balance();
            $this->info('✔ Connexion Notch Pay OK (' . ($client->sandbox() ? 'Sandbox' : 'Production') . ').');
            $this->line('Solde disponible : ' . json_encode($b['available']));
            $this->line('Webhook à déclarer chez Notch Pay : ' . $client->callbackUrl());
            return self::SUCCESS;
        }

        $pending = NotchPayRequest::whereNull('finalized_at')->where('created_at', '>=', now()->subDays(7))
            ->orderBy('last_checked_at')->limit((int) $this->option('limit'))->get();
        foreach ($pending as $req) {
            $before = $req->status;
            $req = $handler->refresh($req);
            if ($req->status !== $before) {
                $this->info("{$req->reference} : {$before} → {$req->status}");
            }
        }
        $this->line("{$pending->count()} opération(s) Notch Pay vérifiée(s).");

        return self::SUCCESS;
    }
}

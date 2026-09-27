<?php

namespace App\Console\Commands;

use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Peex\PeexClient;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexException;
use App\Services\Peex\PeexFlowService;
use App\Services\Peex\PeexStatusHandler;
use Illuminate\Console\Command;

/**
 * Banc de test PEEX en ligne de commande.
 *
 *   php artisan peex ping                                  comptes collect/disbursement/remittance
 *   php artisan peex resolve 065123456                     pays / opérateur détectés
 *   php artisan peex verify +242065123456                  vérification PEEX du numéro
 *   php artisan peex fees 065123456 1000                   frais de collecte PEEX
 *   php artisan peex collect 065123456 100                 MTN CG -> wallet admin
 *   php artisan peex payout 055123456 100                  wallet admin -> Airtel CG
 *   php artisan peex transfer 065123456 055123456 100      MTN CG -> Airtel CG
 *   php artisan peex transfer +237677000001 +242055123456 100
 *   php artisan peex status FP-XXXX-C1                     statut d'une demande
 *   php artisan peex sandbox                               matrice numéros de test PEEX
 *
 * Options : --country=CG (pays par défaut du numéro), --wait=60 (attendre le statut final)
 */
class PeexCommand extends Command
{
    protected $signature = 'peex {action} {args?*} {--country=} {--wait=0 : secondes d\'attente du statut final}';

    protected $description = 'Tests PEEX sandbox : ping, resolve, verify, fees, collect, payout, transfer, status, sandbox';

    public function handle(PeexClient $client, PeexCorridors $corridors, PeexFlowService $flows, PeexStatusHandler $handler): int
    {
        $args = $this->argument('args');
        $country = $this->option('country');

        $this->line('<fg=cyan>PEEX ' . ($client->isSandbox() ? 'SANDBOX' : 'PRODUCTION') . ' — ' . $client->baseUrl() . '</>');

        try {
            return match ($this->argument('action')) {
                'ping' => $this->ping($client),
                'resolve' => $this->dump($corridors->resolve($args[0] ?? '', $country)),
                'verify' => $this->dump($client->verifyPhone($corridors->resolve($args[0] ?? '', $country)['phone'])),
                'fees' => $this->fees($client, $corridors, $args, $country),
                'collect' => $this->follow($flows->cashIn($this->admin(), $args[0], (int) ($args[1] ?? 100), $this->meta($country, 'source')), $handler),
                'payout' => $this->follow($flows->payout($this->admin(), $args[0], (int) ($args[1] ?? 100), $this->meta($country, 'destination')), $handler),
                'transfer' => $this->follow($flows->mobileTransfer($this->admin(), $args[0], $args[1], (int) ($args[2] ?? 100), $this->meta($country, 'source')), $handler),
                'status' => $this->status($args[0] ?? '', $handler),
                'sandbox' => $this->sandboxMatrix($flows, $handler),
                default => $this->invalid(),
            };
        } catch (PeexException $e) {
            $this->error($e->getMessage());
            if ($e->body) {
                $this->dump($e->body);
            }
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error(get_class($e) . ' : ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function ping(PeexClient $client): int
    {
        foreach (['Collect' => 'collectMe', 'Disbursement' => 'disbursementMe', 'Remittance' => 'remittanceMe'] as $label => $m) {
            try {
                $d = $client->{$m}();
                $solde = $d['collect_solde'] ?? $d['disbursement_solde'] ?? $d['solde'] ?? '?';
                $this->info("✔ {$label} : {$d['name']} — activé=" . json_encode($d['is_activated'] ?? null) . " — solde={$solde} — callback=" . ($d['callback_url'] ?? 'non défini'));
            } catch (PeexException $e) {
                $this->warn("✘ {$label} : {$e->getMessage()}");
            }
        }
        $this->line('Callbacks à déclarer chez PEEX : ' . url('/api/webhooks/peex/collect') . ' | /disbursement | /remittance');
        return self::SUCCESS;
    }

    protected function fees(PeexClient $client, PeexCorridors $corridors, array $args, ?string $country): int
    {
        $route = $corridors->resolve($args[0] ?? '', $country);
        return $this->dump($client->collectFees([
            'amount' => (int) ($args[1] ?? 1000),
            'country' => $route['country'],
            'phone_number' => $route['local'],
        ]));
    }

    protected function follow(Transaction $tx, PeexStatusHandler $handler): int
    {
        $wait = (int) $this->option('wait');
        $deadline = time() + $wait;

        do {
            $tx->refresh();
            $this->printTransaction($tx);
            if ($tx->status !== 'processing' || time() >= $deadline) {
                break;
            }
            sleep(5);
            foreach ($tx->peexRequests()->whereNull('finalized_at')->get() as $req) {
                $handler->refresh($req);
            }
        } while (true);

        if ($tx->status === 'processing') {
            $this->comment('En attente de PEEX (confirmation USSD / callback). Suivi : php artisan peex status ' . $tx->reference . '  ou  php artisan peex:sync');
        }

        return in_array($tx->status, ['successful', 'processing'], true) ? self::SUCCESS : self::FAILURE;
    }

    protected function status(string $ref, PeexStatusHandler $handler): int
    {
        $tx = Transaction::where('reference', $ref)->first();
        $reqs = $tx ? $tx->peexRequests : PeexRequest::where('track_id', $ref)->get();
        if ($reqs->isEmpty()) {
            $this->error("Aucune demande PEEX pour {$ref}");
            return self::FAILURE;
        }
        foreach ($reqs as $r) {
            $handler->refresh($r);
        }
        $this->printTransaction($tx ?? $reqs->first()->transaction);
        return self::SUCCESS;
    }

    /** Matrice de test avec les numéros sandbox officiels PEEX (Cameroun). */
    protected function sandboxMatrix(PeexFlowService $flows, PeexStatusHandler $handler): int
    {
        $cases = [
            ['collect', '677000001', 'paid'],
            ['collect', '677100001', 'failed'],
            ['collect', '699000001', 'pending'],
            ['collect', '699100001', 'rejected'],
            ['payout', '677000002', 'paid'],
            ['payout', '677100002', 'failed'],
            ['payout', '699000002', 'pending'],
            ['payout', '699100002', 'rejected'],
        ];

        $rows = [];
        foreach ($cases as [$action, $phone, $expected]) {
            $meta = ['source_country' => 'CM', 'destination_country' => 'CM', 'test' => true];
            $tx = $action === 'collect'
                ? $flows->cashIn($this->admin(), $phone, 100, $meta)
                : $flows->payout($this->admin(), $phone, 100, $meta);
            $rows[] = [$action, $phone, $expected, $tx];
        }

        $this->comment('Attente des statuts PEEX (30 s)...');
        sleep(30);

        $table = [];
        foreach ($rows as [$action, $phone, $expected, $tx]) {
            foreach ($tx->peexRequests()->get() as $r) {
                $handler->refresh($r);
            }
            $tx->refresh();
            $peex = $tx->peexRequests()->latest('id')->first();
            $table[] = [$action, $phone, $expected, $peex?->status ?? '-', $tx->status, $tx->reference, mb_substr((string) $tx->failure_reason, 0, 50)];
        }
        $this->table(['Action', 'Numéro', 'Attendu PEEX', 'Reçu PEEX', 'Transaction', 'Référence', 'Motif'], $table);

        return self::SUCCESS;
    }

    protected function printTransaction(?Transaction $tx): void
    {
        if (! $tx) {
            return;
        }
        $this->newLine();
        $this->line("<options=bold>{$tx->reference}</> {$tx->type} {$tx->source_rail}({$tx->source_account}) → {$tx->destination_rail}({$tx->destination_account}) {$tx->amount} {$tx->currency}");
        $color = match ($tx->status) { 'successful' => 'green', 'processing' => 'yellow', default => 'red' };
        $this->line("Statut : <fg={$color}>{$tx->status}</>" . ($tx->stage ? " ({$tx->stage})" : '') . ($tx->failure_reason ? " — {$tx->failure_reason}" : ''));
        $this->table(
            ['Service', 'track_id', 'Pays', 'Corridor', 'Numéro', 'Statut PEEX', 'Preuve / message'],
            $tx->peexRequests()->get()->map(fn ($r) => [
                $r->service, $r->track_id, $r->country, $r->corridor, $r->phone, $r->status,
                mb_substr((string) ($r->payment_proof ?? $r->message), 0, 60),
            ])->all()
        );
    }

    protected function meta(?string $country, string $side): array
    {
        return array_filter([$side . '_country' => $country ? strtoupper($country) : null, 'test' => true, 'description' => 'Test CLI FlashPay']);
    }

    protected function admin(): User
    {
        return User::role('super_admin')->first() ?? User::firstOrFail();
    }

    protected function dump(array $data): int
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    protected function invalid(): int
    {
        $this->error('Action inconnue. Actions : ping, resolve, verify, fees, collect, payout, transfer, status, sandbox');
        return self::INVALID;
    }
}

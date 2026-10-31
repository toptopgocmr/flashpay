<?php

use Illuminate\Support\Facades\Schedule;

// Sortie des tâches planifiées : dans un conteneur (Railway), on l'écrit sur la
// sortie standard du processus principal pour la voir dans les logs Railway
// (sinon elle part dans /dev/null). En local (Windows), fichier de log.
$scheduleOut = is_writable('/proc/1/fd/1') ? '/proc/1/fd/1' : storage_path('logs/schedule.log');

// En cas d'échec, les dernières lignes de sortie sont recopiées dans les journaux Railway
// (sinon l'erreur reste dans storage/logs/schedule.log, invisible depuis Railway).
$showFailure = function () use ($scheduleOut) {
    if ($scheduleOut !== '/proc/1/fd/1' && is_readable($scheduleOut)) {
        $lines = array_slice(file($scheduleOut, FILE_IGNORE_NEW_LINES) ?: [], -15);
        fwrite(STDOUT, "  ↳ " . implode("\n  ↳ ", $lines) . "\n");
    }
};

// Polling des statuts PEEX en attente (complément des callbacks).
// En local : lancer `php artisan schedule:work` dans un terminal.
Schedule::command('peex:sync')->everyMinute()->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);

// Statuts des versements Digitwace / WacePay en attente (complément du webhook).
Schedule::command('digitwace:sync')->everyMinute()->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);
// Couverture WacePay (pays / opérateurs / collecte / versement), chaque nuit.
Schedule::command('digitwace:sync coverage')->dailyAt('03:10')->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);

// Transferts internationaux (WacePay remittance, TerraPay, PawaPay) : statuts en attente.
Schedule::command('remit:sync')->everyMinute()->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);

// Opérations restées « En attente » sans partenaire qui les traite : réussite, ou rejet + remboursement
// selon les réponses des partenaires (jamais de double versement : un versement ouvert est attendu).
Schedule::command('transactions:resolve-pending --force')->everyFiveMinutes()->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);

// Remboursement des bons de retrait expirés (cash pickup / GAB).
Schedule::command('vouchers:expire')->everyTenMinutes()->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);

// Règlement automatique des marchands (quotidien / hebdomadaire), chaque soir.
Schedule::command('merchants:settle')->dailyAt('20:00')->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);

// Cahier des charges v1.5 : expirations, webhooks e-commerce, réconciliation
Schedule::command('flashpay:maintenance')->everyMinute()->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);
Schedule::command('ledger:reconcile')->hourly()->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);
Schedule::command('ledger:reconcile --daily-reports')->dailyAt('21:30')->withoutOverlapping()->appendOutputTo($scheduleOut)->onFailure($showFailure);

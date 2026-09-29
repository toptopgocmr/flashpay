<?php

use Illuminate\Support\Facades\Schedule;

// Sortie des tâches planifiées : dans un conteneur (Railway), on l'écrit sur la
// sortie standard du processus principal pour la voir dans les logs Railway
// (sinon elle part dans /dev/null). En local (Windows), fichier de log.
$scheduleOut = is_writable('/proc/1/fd/1') ? '/proc/1/fd/1' : storage_path('logs/schedule.log');

// Polling des statuts PEEX en attente (complément des callbacks).
// En local : lancer `php artisan schedule:work` dans un terminal.
Schedule::command('peex:sync')->everyMinute()->withoutOverlapping()->appendOutputTo($scheduleOut);

// Remboursement des bons de retrait expirés (cash pickup / GAB).
Schedule::command('vouchers:expire')->everyTenMinutes()->withoutOverlapping()->appendOutputTo($scheduleOut);

// Règlement automatique des marchands (quotidien / hebdomadaire), chaque soir.
Schedule::command('merchants:settle')->dailyAt('20:00')->withoutOverlapping()->appendOutputTo($scheduleOut);

// Cahier des charges v1.5 : expirations, webhooks e-commerce, réconciliation
Schedule::command('flashpay:maintenance')->everyMinute()->withoutOverlapping()->appendOutputTo($scheduleOut);
Schedule::command('ledger:reconcile')->hourly()->withoutOverlapping()->appendOutputTo($scheduleOut);
Schedule::command('ledger:reconcile --daily-reports')->dailyAt('21:30')->withoutOverlapping()->appendOutputTo($scheduleOut);

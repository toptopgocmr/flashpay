<?php

use Illuminate\Support\Facades\Schedule;

// Polling des statuts PEEX en attente (complément des callbacks).
// En local : lancer `php artisan schedule:work` dans un terminal.
Schedule::command('peex:sync')->everyMinute()->withoutOverlapping();

// Remboursement des bons de retrait expirés (cash pickup / GAB).
Schedule::command('vouchers:expire')->everyTenMinutes()->withoutOverlapping();

// Règlement automatique des marchands (quotidien / hebdomadaire), chaque soir.
Schedule::command('merchants:settle')->dailyAt('20:00')->withoutOverlapping();

// Cahier des charges v1.5 : expirations, webhooks e-commerce, réconciliation
Schedule::command('flashpay:maintenance')->everyMinute()->withoutOverlapping();
Schedule::command('ledger:reconcile')->hourly()->withoutOverlapping();
Schedule::command('ledger:reconcile --daily-reports')->dailyAt('21:30')->withoutOverlapping();

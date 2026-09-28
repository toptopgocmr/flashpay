<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // WampServer : le moteur MySQL par défaut peut être MyISAM (clés limitées
        // à 1000 octets, pas de transactions ni de verrous de ligne) -> on force InnoDB.
        config(['database.connections.mysql.engine' => env('DB_ENGINE', 'InnoDB')]);
        config(['database.connections.mariadb.engine' => env('DB_ENGINE', 'InnoDB')]);
    }

    public function boot(): void
    {
        // Compatibilité index utf8mb4 sur MySQL/MariaDB anciens (limite 767 octets)
        Schema::defaultStringLength(191);

        // En ligne (Railway…) : toutes les URL générées (CSS/JS de la console,
        // liens) en HTTPS, sinon le navigateur bloque les fichiers « http:// »
        // sur une page « https:// » et la console reste blanche.
        if (app()->environment('production') || str_starts_with((string) config('app.url'), 'https://') || env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }
    }
}

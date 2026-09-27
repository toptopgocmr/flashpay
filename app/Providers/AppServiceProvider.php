<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
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
    }
}

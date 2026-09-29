<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Déclaration de perte / vol du téléphone ou de la SIM : tant que ce champ
 * est renseigné, toute connexion est refusée (déblocage en agence / support).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lost_reported_at')->nullable()->after('blocked_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lost_reported_at');
        });
    }
};

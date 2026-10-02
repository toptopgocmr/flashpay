<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Partenaire qui gère les flux de chaque pays : collecte (PEEX) et versement (PEEX ou WacePay / Digitwace). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('corridor_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('corridor_settings', 'collect_partner')) {
                $table->string('collect_partner', 20)->nullable(); // peex
                $table->string('payout_partner', 20)->nullable();  // peex | digitwace
            }
        });
    }

    public function down(): void
    {
        Schema::table('corridor_settings', fn (Blueprint $t) => $t->dropColumn(['collect_partner', 'payout_partner']));
    }
};

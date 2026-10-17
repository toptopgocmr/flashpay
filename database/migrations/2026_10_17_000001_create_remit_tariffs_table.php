<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grille tarifaire des transferts internationaux, séparée de la grille nationale :
 * par pays de destination, type (P2P/P2B/B2P/B2B), mode de réception, partenaire et tranche.
 * « * » = toutes les valeurs. Les paliers « international_transfer » de l'ancienne grille y sont repris.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('remit_tariffs')) {
            Schema::create('remit_tariffs', function (Blueprint $table) {
                $table->id();
                $table->string('country', 3)->default('*')->index();      // ISO2 ou *
                $table->string('business_type', 4)->default('*');         // p2p | p2b | b2p | b2b | *
                $table->string('method', 10)->default('*');               // wallet | bank | cash | *
                $table->string('partner', 20)->default('*');              // wacepay_remit | terrapay | pawapay | *
                $table->unsignedBigInteger('min_amount')->default(0);
                $table->unsignedBigInteger('max_amount')->default(999999999999);
                $table->string('fee_type', 10)->default('percent');       // fixed | percent
                $table->decimal('fee_value', 12, 4)->default(0);
                $table->unsignedBigInteger('min_fee')->default(0);
                $table->unsignedBigInteger('max_fee')->nullable();
                $table->boolean('active')->default(true);
                $table->string('label', 120)->nullable();
                $table->timestamps();
            });
        }
        // Reprise des paliers « Transfert international » de la grille générale
        if (Schema::hasTable('tariffs')) {
            foreach (DB::table('tariffs')->where('operation_type', 'international_transfer')->get() as $t) {
                DB::table('remit_tariffs')->insert([
                    'country' => '*', 'business_type' => '*', 'method' => '*', 'partner' => '*',
                    'min_amount' => $t->min_amount, 'max_amount' => $t->max_amount, 'fee_type' => $t->fee_type,
                    'fee_value' => $t->fee_value, 'min_fee' => $t->min_fee ?? 0, 'max_fee' => $t->max_fee, 'active' => $t->active,
                    'label' => 'Repris de la grille générale', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('tariffs')->where('operation_type', 'international_transfer')->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remit_tariffs');
    }
};

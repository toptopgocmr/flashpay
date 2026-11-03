<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Localiser un agent » : position des agents OfficePay + agences, points de vente
 * et GAB (distributeurs des banques partenaires) affichés sur la carte de l'app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('city');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('address')->nullable()->after('longitude');
            $table->string('opening_hours', 120)->nullable()->after('address');
            $table->boolean('show_on_map')->default(true)->after('opening_hours');
        });

        Schema::create('service_points', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                 // agency | point_of_sale | atm
            $table->string('name', 120);
            $table->string('operator', 120)->nullable(); // banque du GAB, réseau (GIMAC), enseigne
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 2)->default('CG');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('opening_hours', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->json('services')->nullable();       // cash_in, cash_out, atm_withdrawal, account_opening…
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['country', 'type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_points');
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'address', 'opening_hours', 'show_on_map']);
        });
    }
};

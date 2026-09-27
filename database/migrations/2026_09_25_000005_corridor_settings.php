<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activation des corridors depuis la console (surcharge config/corridors.php et .env) :
 * collecte / versement par pays, API de versement.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('corridor_settings', function (Blueprint $table) {
            $table->string('country', 2)->primary();
            $table->boolean('collect')->nullable();
            $table->boolean('payout')->nullable();
            $table->string('payout_api', 20)->nullable();
            $table->string('note', 190)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corridor_settings');
    }
};

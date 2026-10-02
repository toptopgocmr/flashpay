<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Couverture WacePay (synchronisée depuis l'API getPayerCode) et pays ajoutés
 * dynamiquement au catalogue FlashPay (en plus de config/corridors.php).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('wacepay_coverage')) {
            Schema::create('wacepay_coverage', function (Blueprint $table) {
                $table->id();
                $table->string('country', 2)->index();
                $table->string('currency', 3)->nullable();
                $table->string('payer_code', 120);
                $table->string('payer_name', 150)->nullable();
                $table->string('method', 20)->default('wallet'); // wallet | bank | cash
                $table->boolean('payin')->default(false);         // collecte
                $table->boolean('payout')->default(true);         // versement / décaissement
                $table->json('raw')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['country', 'payer_code']);
            });
        }

        if (! Schema::hasTable('corridor_countries')) {
            Schema::create('corridor_countries', function (Blueprint $table) {
                $table->string('country', 2)->primary();
                $table->string('name', 80);
                $table->string('zone', 20)->default('INTERNATIONAL');
                $table->string('dial', 6);
                $table->unsignedTinyInteger('local_length');
                $table->string('currency', 3);
                $table->string('flag', 16)->nullable();
                $table->json('operators')->nullable();
                $table->string('source', 20)->default('wacepay');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('corridor_countries');
        Schema::dropIfExists('wacepay_coverage');
    }
};

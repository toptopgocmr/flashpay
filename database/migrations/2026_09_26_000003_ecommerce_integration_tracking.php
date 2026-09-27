<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Suivi de l'intégration e-commerce de chaque marchand (recette sandbox avant production). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->timestamp('integration_validated_at')->nullable()->after('online_payments');
            $table->string('integration_validated_by', 20)->nullable()->after('integration_validated_at'); // auto | admin
            $table->timestamp('integration_live_at')->nullable()->after('integration_validated_by');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn(['integration_validated_at', 'integration_validated_by', 'integration_live_at']));
    }
};

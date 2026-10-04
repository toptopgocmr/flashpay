<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes de relèvement de plafonds (client -> conformité) avec justificatif,
 * et plafonds personnalisés accordés à un utilisateur (prioritaires sur son palier).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'custom_limits')) {
                $table->json('custom_limits')->nullable();             // {per_operation, daily, monthly, max_balance}
                $table->date('custom_limits_until')->nullable();       // null = sans échéance
            }
        });

        if (! Schema::hasTable('limit_requests')) {
            Schema::create('limit_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('current_tier')->default(0);
                $table->json('current_limits')->nullable();
                $table->unsignedBigInteger('per_operation')->nullable();
                $table->unsignedBigInteger('daily')->nullable();
                $table->unsignedBigInteger('monthly')->nullable();
                $table->unsignedBigInteger('max_balance')->nullable();
                $table->string('currency', 3)->default('XAF');
                $table->string('reason_type', 30);                   // commerce | salaire | evenement | immobilier | autre
                $table->text('justification');
                $table->string('file_mime', 80)->nullable();
                $table->string('file_name', 120)->nullable();
                $table->longText('file_content')->nullable();          // base64 (disque Railway effacé à chaque déploiement)
                $table->string('status', 12)->default('pending')->index(); // pending | approved | rejected | cancelled
                $table->json('granted')->nullable();
                $table->date('granted_until')->nullable();
                $table->string('decision_note', 255)->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_requests');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['custom_limits', 'custom_limits_until']));
    }
};

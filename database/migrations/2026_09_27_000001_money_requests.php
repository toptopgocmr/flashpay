<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'argent entre utilisateurs (« Scanner un ami pour lui demander »).
 * Le demandeur désigne un payeur (scan de son QR, NFC ou numéro) ; le payeur
 * règle depuis son wallet après confirmation par PIN, ou refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payer_phone', 25);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('note', 140)->nullable();
            $table->string('status', 12)->default('pending'); // pending | paid | declined | cancelled | expired
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['payer_id', 'status']);
            $table->index(['payer_phone', 'status']);
            $table->index(['requester_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_requests');
    }
};

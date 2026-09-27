<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->enum('type', [
                'p2p', 'merchant_payment', 'cash_in', 'cash_out',
                'collection', 'withdrawal', 'qr_payment', 'nfc_payment', 'manual_payment',
            ]);
            $table->enum('source_rail', ['mtn_momo', 'airtel_money', 'gimacpay', 'peex', 'bank', 'wallet']);
            $table->enum('destination_rail', ['mtn_momo', 'airtel_money', 'gimacpay', 'peex', 'bank', 'wallet']);
            $table->string('source_account')->nullable();
            $table->string('destination_account')->nullable();
            $table->foreignId('source_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('destination_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->string('source_external_ref')->nullable();
            $table->string('destination_external_ref')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->enum('status', ['processing', 'successful', 'failed', 'reversed'])->default('processing');
            $table->string('failure_reason')->nullable();
            // Étape d'attente pour les rails asynchrones (PEEX) :
            // awaiting_source | awaiting_destination | null
            $table->string('stage')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('initiated_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('transaction_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_notes');
        Schema::dropIfExists('transactions');
    }
};

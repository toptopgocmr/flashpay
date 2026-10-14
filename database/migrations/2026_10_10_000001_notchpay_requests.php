<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Opérations envoyées à Notch Pay (collecte direct charge / versement transfer). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('notchpay_requests')) {
            Schema::create('notchpay_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->string('reference', 64)->unique();           // référence FlashPay envoyée à Notch Pay
                $table->string('notch_ref', 120)->nullable()->index(); // référence / id Notch Pay (trx.… / trf_…)
                $table->string('operation', 20)->default('payout');   // payin | payout
                $table->string('channel', 40)->nullable();            // cm.mtn, sn.wave…
                $table->string('status', 20)->default('new');         // new | pending | successful | failed
                $table->string('raw_status', 50)->nullable();
                $table->string('message', 255)->nullable();
                $table->json('last_response')->nullable();
                $table->json('last_callback')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notchpay_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Journal de chaque appel PEEX (collecte, décaissement, remittance) :
// sert au rapprochement callback/polling et à l'audit.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('peex_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service', 20);            // collect | disbursement | remittance
            $table->string('track_id')->unique();     // référence envoyée à PEEX
            $table->string('peex_id')->nullable();    // id retourné par PEEX
            $table->string('country', 2);
            $table->string('corridor')->nullable();   // ex: mtn-cg, airtel-cg
            $table->string('phone');                  // format international +242...
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->decimal('fees', 12, 2)->nullable();
            $table->string('status', 20)->default('new'); // statut PEEX brut
            $table->string('payment_proof')->nullable();
            $table->text('message')->nullable();
            $table->boolean('sandbox')->default(true);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('last_callback')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('finalized_at')->nullable(); // statut final traité par le Switch
            $table->timestamps();

            $table->index(['status', 'finalized_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peex_requests');
    }
};

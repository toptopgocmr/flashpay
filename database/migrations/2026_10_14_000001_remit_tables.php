<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Transferts internationaux : opérations envoyées à WacePay (API v2) / TerraPay, et codes expéditeur / bénéficiaire. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('remit_requests')) {
            Schema::create('remit_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->string('partner', 20);                        // wacepay_remit | terrapay
                $table->string('reference', 64)->unique();             // référence FlashPay
                $table->string('partner_ref', 120)->nullable()->index(); // WPPX… (WacePay) / TPKM… (TerraPay)
                $table->string('method', 12)->nullable();              // wallet | bank | cash
                $table->string('business_type', 4)->nullable();        // p2p | p2b | b2p | b2b
                $table->string('status', 20)->default('new');          // new | pending | successful | failed
                $table->string('raw_status', 60)->nullable();
                $table->string('message', 255)->nullable();
                $table->json('last_response')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('remit_parties')) {
            Schema::create('remit_parties', function (Blueprint $table) {
                $table->id();
                $table->string('partner', 20);
                $table->string('kind', 12);          // sender | beneficiary
                $table->string('key', 64);           // empreinte des données d'identité
                $table->string('code', 120);         // senderCode / beneficiaryCode
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
                $table->unique(['partner', 'kind', 'key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remit_parties');
        Schema::dropIfExists('remit_requests');
    }
};

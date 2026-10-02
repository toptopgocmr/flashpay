<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Cadeaux : montant recrédité à l'expéditeur (annulation / expiration).
 * - Demandes d'argent : canal d'envoi choisi (app, sms, whatsapp, lien).
 * - Digitwace (WacePay) : suivi des demandes de versement et cache des
 *   codes expéditeur / bénéficiaire (recommandations de charge WacePay).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('gift_envelopes', function (Blueprint $table) {
            if (! Schema::hasColumn('gift_envelopes', 'refunded_amount')) {
                $table->unsignedBigInteger('refunded_amount')->default(0);
            }
        });

        Schema::table('money_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('money_requests', 'channel')) {
                $table->string('channel', 15)->default('app'); // app | sms | whatsapp | link
            }
        });

        if (! Schema::hasTable('digitwace_requests')) {
            Schema::create('digitwace_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
                $table->string('reference', 64)->unique();         // référence FlashPay envoyée à WacePay
                $table->string('wace_id', 100)->nullable()->index(); // identifiant / code transaction WacePay
                $table->string('operation', 20)->default('payout'); // payout | payin
                $table->string('status', 20)->default('new');       // new | pending | successful | failed
                $table->string('raw_status', 50)->nullable();
                $table->string('message', 255)->nullable();
                $table->json('last_response')->nullable();
                $table->json('last_callback')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('digitwace_parties')) {
            Schema::create('digitwace_parties', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 12);          // sender | beneficiary
                $table->string('key', 120);           // téléphone + pays (+ nom)
                $table->string('code', 120);          // senderCode / beneficiaryCode WacePay
                $table->timestamps();
                $table->unique(['kind', 'key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('digitwace_parties');
        Schema::dropIfExists('digitwace_requests');
        Schema::table('money_requests', fn (Blueprint $t) => $t->dropColumn('channel'));
        Schema::table('gift_envelopes', fn (Blueprint $t) => $t->dropColumn('refunded_amount'));
    }
};

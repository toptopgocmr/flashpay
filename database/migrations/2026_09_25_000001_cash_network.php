<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réseau cash FlashPay :
 *  - withdrawal_vouchers : bons de retrait (cash pickup chez un agent, GAB partenaire)
 *  - pay_codes           : codes de paiement client à usage unique (style Alipay / WeChat Pay),
 *                          scannés par un marchand (paiement) ou un agent (dépôt cash)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('withdrawal_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);                 // cash_pickup | atm
            $table->string('country', 2);
            $table->string('code_hash', 64)->unique();     // sha256 du code (recherche)
            $table->text('code_encrypted');                // code lisible par le client uniquement
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('currency', 3);
            $table->string('beneficiary_name')->nullable();
            $table->string('beneficiary_phone', 25)->nullable();
            $table->string('status', 15)->default('pending'); // pending | redeemed | cancelled | expired
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete(); // agent
            $table->string('partner_ref')->nullable();     // référence banque / GAB
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('pay_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('status', 10)->default('active'); // active | used | revoked
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete(); // marchand / agent
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_codes');
        Schema::dropIfExists('withdrawal_vouchers');
    }
};

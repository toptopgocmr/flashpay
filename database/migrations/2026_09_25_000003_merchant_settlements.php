<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règlement des marchands : plusieurs comptes de règlement (mobile money de
 * tout opérateur, compte bancaire, wallet FlashPay, retrait cash agent) et
 * règlement automatique (quotidien / hebdomadaire) vers le compte par défaut.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('settlement_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                 // mobile_money | bank | wallet | cash_pickup
            $table->string('label', 80)->nullable();    // ex. « MTN caisse », « BGFI principal »
            $table->string('country', 2);
            $table->string('phone', 25)->nullable();    // mobile money / wallet FlashPay
            $table->string('operator', 60)->nullable(); // MTN Mobile Money, Airtel Money…
            $table->string('bank_name', 120)->nullable();
            $table->string('account_holder', 120)->nullable();
            $table->string('account_number', 60)->nullable(); // RIB / IBAN
            $table->string('swift', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['merchant_id', 'is_default']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('auto_settlement', 10)->default('none')->after('settlement_phone'); // none | daily | weekly
            $table->unsignedBigInteger('auto_settlement_min')->default(0)->after('auto_settlement'); // solde à conserver
            $table->timestamp('last_auto_settlement_at')->nullable()->after('auto_settlement_min');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn(['auto_settlement', 'auto_settlement_min', 'last_auto_settlement_at']));
        Schema::dropIfExists('settlement_accounts');
    }
};

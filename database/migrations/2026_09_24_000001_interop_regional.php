<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interopérabilité sous-régionale (CEMAC, UEMOA, RDC, Guinée) :
 *  - rails libres (orange_money, moov_money… en plus de MTN / Airtel)
 *  - montant / devise reçus (change XAF ↔ XOF ↔ CDF ↔ GNF)
 *  - frais marchand, pays des wallets
 *  - grille tarifaire par zone (national / régional / international)
 *  - taux de change administrables
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('source_rail', 30)->change();
            $table->string('destination_rail', 30)->change();
            $table->string('type', 30)->change();
            $table->unsignedBigInteger('merchant_fee')->default(0)->after('fee');
            $table->unsignedBigInteger('destination_amount')->nullable()->after('currency');
            $table->string('destination_currency', 3)->nullable()->after('destination_amount');
            $table->string('scope', 15)->nullable()->after('type'); // national | regional | international
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('currency');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('business_category');
            // Numéro mobile money de règlement (retrait automatique possible)
            $table->string('settlement_phone')->nullable()->after('country');
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->string('scope', 15)->default('national')->after('operation_type');
            $table->unsignedBigInteger('min_fee')->default(0)->after('fee_value');
            $table->unsignedBigInteger('max_fee')->nullable()->after('min_fee');
            $table->index(['operation_type', 'scope', 'active']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base', 3);   // devise source
            $table->string('quote', 3);  // devise cible
            $table->decimal('rate', 18, 8); // 1 base = rate quote
            $table->decimal('margin_percent', 5, 2)->default(0); // marge FlashPay appliquée au client
            $table->boolean('active')->default(true);
            $table->string('source')->nullable(); // manuel, BEAC, BCEAO…
            $table->timestamps();
            $table->unique(['base', 'quote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropIndex(['operation_type', 'scope', 'active']);
            $table->dropColumn(['scope', 'min_fee', 'max_fee']);
        });
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn(['country', 'settlement_phone']));
        Schema::table('wallets', fn (Blueprint $t) => $t->dropColumn('country'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn(['merchant_fee', 'destination_amount', 'destination_currency', 'scope']));
    }
};

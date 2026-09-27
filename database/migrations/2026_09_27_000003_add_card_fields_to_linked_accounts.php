<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes liés (§3.3.5) : ajout d'un 3e type « card » (carte bancaire Visa /
 * Mastercard...) à côté de mobile_money et bank (virement IBAN/RIB).
 *
 * Important sécurité / PCI-DSS : on ne stocke jamais le PAN complet ni le
 * CVV. Seuls le réseau (brand), les 4 derniers chiffres et la date
 * d'expiration sont conservés — le numéro complet transite en clair depuis
 * le formulaire mais est tronqué côté contrôleur avant persistance.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->string('card_brand', 20)->nullable()->after('account_number');   // Visa | Mastercard | Amex...
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->string('card_expiry', 5)->nullable()->after('card_last4');       // MM/AA
        });
    }

    public function down(): void
    {
        Schema::table('linked_accounts', function (Blueprint $table) {
            $table->dropColumn(['card_brand', 'card_last4', 'card_expiry']);
        });
    }
};

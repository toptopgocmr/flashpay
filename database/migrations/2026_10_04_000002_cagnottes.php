<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cagnotte (évolution du partage de note) : facture partagée ou cadeau commun,
 * bénéficiaire désigné par son numéro (l'argent de chaque contribution lui
 * arrive directement), parts égales / fixées / montant libre, date limite,
 * clôture avec reçu récapitulatif.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bill_splits', function (Blueprint $table) {
            if (! Schema::hasColumn('bill_splits', 'purpose')) {
                $table->string('purpose', 10)->default('bill')->after('title'); // bill | gift
            }
            if (! Schema::hasColumn('bill_splits', 'beneficiary_user_id')) {
                $table->foreignId('beneficiary_user_id')->nullable()->after('creator_id')->constrained('users')->nullOnDelete();
                $table->string('beneficiary_phone', 25)->nullable()->after('beneficiary_user_id');
                $table->string('beneficiary_name', 120)->nullable()->after('beneficiary_phone');
            }
            if (! Schema::hasColumn('bill_splits', 'message')) {
                $table->string('message', 255)->nullable()->after('purpose');
                $table->date('deadline')->nullable()->after('status');
                $table->timestamp('closed_at')->nullable()->after('deadline');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bill_splits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('beneficiary_user_id');
            $table->dropColumn(['purpose', 'beneficiary_phone', 'beneficiary_name', 'message', 'deadline', 'closed_at']);
        });
    }
};

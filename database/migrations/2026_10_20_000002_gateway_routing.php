<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. remit_requests : une référence par PARTENAIRE (et non plus globale).
 *    MaishaPay et PawaPay partagent la table : lors d'une bascule de versement
 *    MaishaPay → PawaPay (même référence FP-…), PawaPay réutilisait la ligne
 *    MaishaPay ; le versement PawaPay était alors suivi comme « MaishaPay ».
 *    Réparation : une ligne « maishapay » portant un identifiant pawaPay (UUID)
 *    est rendue à pawapay_momo.
 * 2. corridor_settings : mode de routage (manuel / mixte / automatique) et
 *    passerelles de secours par flux (collecte, versement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remit_requests', function (Blueprint $table) {
            $table->dropUnique(['reference']);
        });
        Schema::table('remit_requests', function (Blueprint $table) {
            $table->unique(['partner', 'reference']);
            $table->index('reference');
        });
        DB::table('remit_requests')->where('partner', 'maishapay')->whereNotNull('partner_ref')
            ->get(['id', 'partner_ref'])
            ->filter(fn ($r) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $r->partner_ref))
            ->each(fn ($r) => DB::table('remit_requests')->where('id', $r->id)->update(['partner' => 'pawapay_momo', 'message' => null]));

        Schema::table('corridor_settings', function (Blueprint $table) {
            $table->string('routing_mode', 12)->nullable()->after('payout_partner'); // fixed | mixed | auto (null = auto)
            $table->json('collect_backups')->nullable()->after('routing_mode');
            $table->json('payout_backups')->nullable()->after('collect_backups');
        });
    }

    public function down(): void
    {
        Schema::table('corridor_settings', function (Blueprint $table) {
            $table->dropColumn(['routing_mode', 'collect_backups', 'payout_backups']);
        });
        Schema::table('remit_requests', function (Blueprint $table) {
            $table->dropUnique(['partner', 'reference']);
            $table->dropIndex(['reference']);
            $table->unique('reference');
        });
    }
};

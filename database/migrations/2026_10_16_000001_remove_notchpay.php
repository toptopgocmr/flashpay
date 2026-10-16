<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait de Notch Pay (octobre 2026) : les pays confiés à Notch Pay dans la
 * console repassent sur leur partenaire par défaut, et la table de suivi est supprimée.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('corridor_settings') && Schema::hasColumn('corridor_settings', 'collect_partner')) {
            DB::table('corridor_settings')->where('collect_partner', 'notchpay')->update(['collect_partner' => null]);
            DB::table('corridor_settings')->where('payout_partner', 'notchpay')->update(['payout_partner' => null]);
        }
        Schema::dropIfExists('notchpay_requests');
        // Ancienne entrée de migration (fichier retiré du code)
        if (Schema::hasTable('migrations')) {
            DB::table('migrations')->where('migration', '2026_10_10_000001_notchpay_requests')->delete();
        }
    }

    public function down(): void
    {
        // Notch Pay retiré : pas de retour arrière
    }
};

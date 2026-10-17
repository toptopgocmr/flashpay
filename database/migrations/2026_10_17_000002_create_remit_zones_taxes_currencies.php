<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normes de tarification des transferts internationaux (modèle SEND-PAZ) :
 *  - zones (nom, description, seuil = montant maximum par transfert, pays) ;
 *  - grille par zone et tranche (frais hors taxes) ;
 *  - taxes (pourcentage ou fixe, sur le montant ou sur les frais, par zone ou toutes zones) ;
 *  - taux de change à partir du Congo : valeur en XAF d'une unité de devise.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('remit_zones')) {
            Schema::create('remit_zones', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('description', 190)->nullable();
                $table->unsignedBigInteger('threshold')->nullable(); // seuil : montant maximum par transfert (XAF)
                $table->json('countries')->nullable();               // ISO2
                $table->boolean('is_default')->default(false);      // pays non classés
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
            $eu = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
                'GB', 'CH', 'NO', 'IS', 'LI', 'MC', 'AD', 'SM', 'VA', 'AL', 'BA', 'ME', 'MK', 'RS', 'XK', 'MD', 'UA', 'GI'];
            $now = now();
            DB::table('remit_zones')->insert([
                ['name' => 'CEMAC', 'description' => 'Communauté Économique d\'Afrique Centrale', 'threshold' => 3500000, 'countries' => json_encode(['CM', 'GA', 'TD', 'CF', 'GQ']), 'is_default' => false, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Afrique de l\'Ouest', 'description' => 'Afrique de l\'Ouest (UEMOA et CEDEAO)', 'threshold' => 1000000, 'countries' => json_encode(['SN', 'CI', 'ML', 'BF', 'BJ', 'TG', 'NE', 'GW', 'GN', 'GH', 'NG', 'LR', 'SL', 'GM', 'CV', 'MR']), 'is_default' => false, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'EUROPE', 'description' => 'Europe', 'threshold' => 1000000, 'countries' => json_encode($eu), 'is_default' => false, 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'World/International', 'description' => 'Le monde (pays non classés dans une autre zone)', 'threshold' => 30000000, 'countries' => json_encode([]), 'is_default' => true, 'sort' => 9, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (Schema::hasTable('remit_tariffs') && ! Schema::hasColumn('remit_tariffs', 'zone_id')) {
            Schema::table('remit_tariffs', function (Blueprint $table) {
                $table->foreignId('zone_id')->nullable()->after('id')->constrained('remit_zones')->nullOnDelete();
            });
        }

        // Grille de référence par zone (frais hors taxes) — modifiable dans la console
        if (Schema::hasColumn('remit_tariffs', 'zone_id') && ! DB::table('remit_tariffs')->whereNotNull('zone_id')->exists()) {
            $z = DB::table('remit_zones')->pluck('id', 'name');
            $now = now();
            $grid = [
                ['CEMAC', 0, 25000, 2975], ['CEMAC', 25001, 50000, 4300],
                ['Afrique de l\'Ouest', 1, 60000, 6600],
                ['EUROPE', 1, 60000, 8000],
                ['World/International', 1, 60000, 8000],
            ];
            foreach ($grid as [$zone, $a, $b, $ht]) {
                if (isset($z[$zone])) {
                    DB::table('remit_tariffs')->insert([
                        'zone_id' => $z[$zone], 'country' => '*', 'business_type' => '*', 'method' => '*', 'partner' => '*',
                        'min_amount' => $a, 'max_amount' => $b, 'fee_type' => 'fixed', 'fee_value' => $ht, 'min_fee' => 0, 'max_fee' => null,
                        'active' => true, 'label' => $zone . ' ' . $a . '-' . $b, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        if (! Schema::hasTable('remit_taxes')) {
            Schema::create('remit_taxes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('label', 100);
                $table->string('type', 10)->default('percent'); // percent | fixed
                $table->decimal('value', 12, 4)->default(0);
                $table->string('base', 10)->default('amount');  // amount (montant) | fee (frais) | none (fixe)
                $table->foreignId('zone_id')->nullable()->constrained('remit_zones')->nullOnDelete(); // null = toutes zones
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
            $now = now();
            DB::table('remit_taxes')->insert([
                ['code' => 'COBAC', 'label' => 'Commission COBAC', 'type' => 'percent', 'value' => 0.25, 'base' => 'amount', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'TIMBRE', 'label' => 'Timbre électronique', 'type' => 'fixed', 'value' => 50, 'base' => 'none', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'TTF', 'label' => 'TTF', 'type' => 'percent', 'value' => 1.5, 'base' => 'amount', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'TVA', 'label' => 'TVA', 'type' => 'percent', 'value' => 18.9, 'base' => 'fee', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (! Schema::hasTable('remit_currencies')) {
            Schema::create('remit_currencies', function (Blueprint $table) {
                $table->id();
                $table->string('code', 3)->unique();
                $table->string('symbol', 12)->nullable();
                $table->decimal('xaf_value', 20, 8);               // valeur en XAF d'une unité (1 EUR = 710 XAF)
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
            $now = now();
            $rows = ['XAF' => ['FCFA', 1], 'XOF' => ['FCFA', 1]];
            // Reprise des taux de la console (1 XAF = r devise, marge comprise) → valeur en XAF d'une unité
            if (Schema::hasTable('exchange_rates')) {
                foreach (DB::table('exchange_rates')->where('base', 'XAF')->where('active', true)->get() as $r) {
                    $eff = (float) $r->rate * (1 - (float) $r->margin_percent / 100);
                    if ($eff > 0 && ! isset($rows[$r->quote])) {
                        $rows[$r->quote] = [null, round(1 / $eff, 8)];
                    }
                }
            }
            $rows['EUR'] ??= ['€', 655.957];
            $rows['EUR'][0] = '€';
            foreach ($rows as $code => [$sym, $val]) {
                DB::table('remit_currencies')->insert(['code' => $code, 'symbol' => $sym ?? $code, 'xaf_value' => $val, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remit_currencies');
        Schema::dropIfExists('remit_taxes');
        if (Schema::hasColumn('remit_tariffs', 'zone_id')) {
            Schema::table('remit_tariffs', fn (Blueprint $t) => $t->dropConstrainedForeignId('zone_id'));
        }
        Schema::dropIfExists('remit_zones');
    }
};

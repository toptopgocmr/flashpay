<?php

use App\Models\PlatformSetting;
use App\Support\PartnerFees;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Offre commerciale WacePay (Digitwace) du 06/10/2026 : applique les tarifs
 * contractuels aux frais partenaires (PayIn 3,5 % ; PayOut par pays).
 * Les autres tarifs saisis dans la console sont conservés.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = PlatformSetting::find('partner_fees');
        $value = (array) ($row?->value ?? []);
        foreach (['digitwace_collect', 'digitwace_payout'] as $k) {
            $value[$k] = PartnerFees::DEFAULTS[$k];
        }
        PlatformSetting::updateOrCreate(['key' => 'partner_fees'], ['value' => $value]);
        Cache::forget('platform_setting:partner_fees');
    }

    public function down(): void
    {
        // Les tarifs restent modifiables dans Transactions › Tarifs partenaires.
    }
};

<?php

use App\Support\Phone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Harmonise les numéros déjà enregistrés au format international
 * (ex. 067621919 → 242067621919). En cas de doublon, le numéro est laissé tel quel.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('users')->select('id', 'phone')->get() as $u) {
            $n = Phone::normalize($u->phone);
            if ($n && $n !== $u->phone && ! DB::table('users')->where('phone', $n)->where('id', '<>', $u->id)->exists()) {
                DB::table('users')->where('id', $u->id)->update(['phone' => $n]);
            }
        }
    }

    public function down(): void
    {
        // irréversible (format d'origine non conservé)
    }
};

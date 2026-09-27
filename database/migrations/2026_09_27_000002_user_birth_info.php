<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date et lieu de naissance de l'utilisateur : renseignés à l'inscription
 * (étape 1) ou complétés/modifiés ensuite depuis l'écran KYC.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('id_number');
            $table->string('place_of_birth', 150)->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'place_of_birth']);
        });
    }
};

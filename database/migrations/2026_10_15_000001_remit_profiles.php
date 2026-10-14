<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil « international » de l'expéditeur (KYC remittance) : collecté une fois,
 * réutilisé à chaque transfert. Données d'identité chiffrées (colonne data).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('remit_profiles')) {
            Schema::create('remit_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->text('data');                              // JSON chiffré (identité, pièce, adresse)
                $table->string('email', 150)->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->date('id_expiry')->nullable();             // en clair pour bloquer les pièces expirées
                $table->decimal('gps_lat', 10, 7)->nullable();
                $table->decimal('gps_lng', 10, 7)->nullable();
                $table->timestamp('gps_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remit_profiles');
    }
};

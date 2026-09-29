<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copie des pièces KYC en base : le disque d'un conteneur Railway est effacé à
 * chaque redéploiement, les photos devenaient alors introuvables (mobile + admin).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('kyc_documents', 'mime')) {
                $table->string('mime', 80)->nullable()->after('path');
            }
            if (! Schema::hasColumn('kyc_documents', 'content')) {
                $table->longText('content')->nullable()->after('mime'); // base64
            }
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropColumn(['mime', 'content']);
        });
    }
};

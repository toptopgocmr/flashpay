<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pays et ville des agents et des marchands (réseau par pays / ville). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('zone');
            $table->string('city', 80)->nullable()->after('country');
            $table->index(['country', 'city']);
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('city', 80)->nullable()->after('country');
            $table->string('address')->nullable()->after('city');
            $table->index(['country', 'city']);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['country', 'city']);
            $table->dropColumn(['country', 'city']);
        });
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropIndex(['country', 'city']);
            $table->dropColumn(['city', 'address']);
        });
    }
};

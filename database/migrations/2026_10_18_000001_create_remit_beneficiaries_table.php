<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Carnet de bénéficiaires internationaux (pays, mode de réception, compte, identité — chiffrés). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remit_beneficiaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('label', 120);
            $t->string('country', 2);
            $t->string('method', 10); // wallet | bank | cash
            $t->string('business_type', 4)->default('p2p');
            $t->string('service_name', 80)->nullable();
            $t->string('account_hint', 60)->nullable(); // numéro / compte masqué, pour l'affichage
            $t->string('fingerprint', 64);
            $t->text('data'); // encrypted:array — beneficiary, account, service, relation
            $t->unsignedInteger('uses')->default(0);
            $t->boolean('favorite')->default(false);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'fingerprint']);
            $t->index(['user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remit_beneficiaries');
    }
};

<?php

use App\Models\Transaction;
use App\Services\Beneficiaries\BeneficiaryBook;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Carnet de bénéficiaires du client, rempli avec les envois déjà effectués. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_beneficiaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 25);
            $t->string('deliver_to', 10)->default('mobile'); // wallet | mobile
            $t->string('name', 120)->nullable();
            $t->string('country', 2)->nullable();
            $t->string('operator', 60)->nullable();
            $t->string('currency', 3)->nullable();
            $t->unsignedBigInteger('last_amount')->nullable();
            $t->unsignedBigInteger('last_transaction_id')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->boolean('favorite')->default(false);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'phone', 'deliver_to']);
            $t->index(['user_id', 'last_used_at']);
        });

        // Envois déjà effectués : on remplit le carnet de chaque client
        $book = new BeneficiaryBook();
        Transaction::whereIn('type', BeneficiaryBook::TYPES)->whereNotNull('initiated_by')
            ->whereIn('status', ['successful', 'processing'])->orderBy('id')
            ->chunkById(500, function ($txs) use ($book) {
                foreach ($txs as $tx) {
                    try {
                        $book->remember($tx);
                    } catch (\Throwable) {
                        // une ligne illisible ne bloque pas la migration
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_beneficiaries');
    }
};

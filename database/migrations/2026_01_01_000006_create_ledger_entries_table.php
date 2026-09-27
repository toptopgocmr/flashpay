<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('account'); // ex: wallet:12, mtn_momo:242xxxxxxx, flashpay:suspense
            $table->enum('type', ['debit', 'credit']);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->unsignedBigInteger('balance_after')->nullable();
            $table->string('memo')->nullable();
            $table->timestamps();

            $table->index('account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Litiges : réponse de la partie adverse (marchand, agent, client) et payeur du remboursement. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            if (! Schema::hasColumn('disputes', 'counterparty_response')) {
                $table->text('counterparty_response')->nullable();
                $table->timestamp('counterparty_responded_at')->nullable();
                $table->string('refunded_from', 20)->nullable(); // counterparty | flashpay | merchant
            }
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn(['counterparty_response', 'counterparty_responded_at', 'refunded_from']);
        });
    }
};

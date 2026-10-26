<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait cash : l'expéditeur confirme dans l'application avant que l'agent remette les espèces.
 *  - confirmation_status : awaiting_sender | approved | rejected
 *  - confirmation_agent_user_id : agent qui a saisi le code (seul lui peut ensuite payer)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('withdrawal_vouchers', function (Blueprint $table) {
            $table->string('confirmation_status', 20)->nullable()->after('status');
            $table->unsignedBigInteger('confirmation_agent_user_id')->nullable()->after('confirmation_status');
            $table->timestamp('confirmation_requested_at')->nullable()->after('confirmation_agent_user_id');
            $table->timestamp('confirmed_at')->nullable()->after('confirmation_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_vouchers', function (Blueprint $table) {
            $table->dropColumn(['confirmation_status', 'confirmation_agent_user_id', 'confirmation_requested_at', 'confirmed_at']);
        });
    }
};

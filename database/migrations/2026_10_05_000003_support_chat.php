<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support par chat (texte, photos, notes vocales, appels) : la discussion
 * client <-> « Support FlashPay » est une conversation de type support ;
 * l'agent de la console qui répond ou décroche est tracé (agent_id).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_conversations', 'kind')) {
                $table->string('kind', 10)->default('direct')->index();     // direct | support
                $table->string('status', 10)->default('open');               // open | closed (support)
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            }
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_messages', 'agent_id')) {
                $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
        Schema::table('chat_calls', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_calls', 'agent_id')) {
                $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_calls', fn (Blueprint $t) => $t->dropConstrainedForeignId('agent_id'));
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('agent_id'));
        Schema::table('chat_conversations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assigned_to');
            $t->dropColumn(['kind', 'status']);
        });
    }
};

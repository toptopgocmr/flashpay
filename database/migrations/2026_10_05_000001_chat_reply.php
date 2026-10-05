<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Chat : réponse ciblée à un message (citation, comme WhatsApp). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('chat_messages', 'reply_to_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreignId('reply_to_id')->nullable()->after('sender_id')->constrained('chat_messages')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('chat_messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('reply_to_id'));
    }
};

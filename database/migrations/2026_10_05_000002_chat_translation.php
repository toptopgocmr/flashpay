<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat traduit automatiquement (comme Alibaba) : chaque message garde la langue
 * de son auteur ; la traduction dans la langue du lecteur est faite une fois
 * puis mise en cache.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('chat_messages', 'lang')) {
            Schema::table('chat_messages', fn (Blueprint $t) => $t->string('lang', 5)->nullable()->after('body'));
        }
        if (! Schema::hasTable('chat_message_translations')) {
            Schema::create('chat_message_translations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
                $table->string('lang', 5);
                $table->text('text');
                $table->string('engine', 20)->nullable();
                $table->timestamps();
                $table->unique(['message_id', 'lang']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_translations');
        if (Schema::hasColumn('chat_messages', 'lang')) {
            Schema::table('chat_messages', fn (Blueprint $t) => $t->dropColumn('lang'));
        }
    }
};

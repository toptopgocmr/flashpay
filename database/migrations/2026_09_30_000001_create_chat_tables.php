<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messagerie entre utilisateurs FlashPay (texte, photos, captures, vidéos courtes).
 * Les fichiers sont stockés en base (base64) : le disque Railway est effacé à
 * chaque redéploiement. Tailles limitées dans ChatController.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete(); // plus petit id
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['user_one_id', 'user_two_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10)->default('text'); // text | image | video
            $table->text('body')->nullable();
            $table->string('mime', 80)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->longText('content')->nullable(); // base64
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};

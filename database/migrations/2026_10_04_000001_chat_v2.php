<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat v2 (style WhatsApp) :
 *  - messages modifiés (edited_at), transférés (forwarded), notes vocales (type audio + duration) ;
 *  - appels audio WebRTC (chat_calls) : la signalisation (offre / réponse SDP)
 *    passe par l'API, l'audio circule ensuite directement entre les téléphones.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('read_at');
            }
            if (! Schema::hasColumn('chat_messages', 'forwarded')) {
                $table->boolean('forwarded')->default(false)->after('type');
            }
            if (! Schema::hasColumn('chat_messages', 'duration')) {
                $table->unsignedInteger('duration')->nullable()->after('size'); // secondes (note vocale, appel)
            }
        });

        if (! Schema::hasTable('chat_calls')) {
            Schema::create('chat_calls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
                // ringing | accepted | ended | rejected | missed | cancelled | busy
                $table->string('status', 12)->default('ringing')->index();
                $table->longText('offer')->nullable();   // SDP de l'appelant
                $table->longText('answer')->nullable();  // SDP de l'appelé
                $table->timestamp('answered_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['callee_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_calls');
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'forwarded', 'duration']);
        });
    }
};

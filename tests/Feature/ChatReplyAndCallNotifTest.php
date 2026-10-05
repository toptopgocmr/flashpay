<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ChatConversation;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ChatReplyAndCallNotifTest extends TestCase
{
    protected function mk(string $phone): User
    {
        $u = User::create(['full_name' => "User {$phone}", 'phone' => $phone, 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        return $u;
    }

    public function test_reply_to_message_and_incoming_call_notification(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $a = $this->mk('242061777001');
        $b = $this->mk('242061777002');
        $c = ChatConversation::between($a, $b);

        $this->actingAs($a, 'sanctum');
        $first = $this->postJson("/api/chats/{$c->id}/messages", ['body' => 'On se voit à 18 h ?'])->assertCreated()->json();

        $this->actingAs($b, 'sanctum');
        $reply = $this->postJson("/api/chats/{$c->id}/messages", ['body' => 'Oui, parfait', 'reply_to_id' => $first['id']])->assertCreated()->json();
        $this->assertSame($first['id'], $reply['reply_to']['id']);
        $this->assertSame('On se voit à 18 h ?', $reply['reply_to']['body']);
        $this->assertFalse($reply['reply_to']['mine']);

        // Appel : le contact reçoit une notification « appel entrant »
        $this->postJson("/api/chats/{$c->id}/calls", ['offer' => 'v=0 fake-sdp'])->assertCreated()->assertJsonStructure(['ice_servers']);
        $this->assertTrue(AppNotification::where('user_id', $a->id)->where('type', 'chat_call')->exists());
    }
}

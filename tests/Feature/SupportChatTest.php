<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Models\Wallet;
use App\Support\SupportChat;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    protected function mk(string $phone): User
    {
        $u = User::create(['full_name' => "Client {$phone}", 'phone' => $phone, 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        return $u;
    }

    public function test_client_chats_and_calls_support_and_admin_monitors_everything(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        SupportChat::reset();
        $admin = User::where('phone', '242060000000')->first();
        $client = $this->mk('242061999001');
        $friend = $this->mk('242061999002');

        // Le client ouvre le chat support et écrit
        $this->actingAs($client, 'sanctum');
        $conv = $this->postJson('/api/chats/support')->assertOk()->assertJsonPath('user.name', 'Support FlashPay')->json();
        $this->postJson("/api/chats/{$conv['id']}/messages", ['body' => 'Mon retrait est bloqué'])->assertCreated();
        $this->assertTrue(AppNotification::where('audience', 'admin')->where('type', 'support_chat')->exists());

        // La console voit la discussion et répond au nom du support
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/support/chat')->assertOk()->assertJsonPath('data.0.unread', 1)->assertJsonPath('data.0.client.id', $client->id);
        $this->getJson("/api/support/chat/{$conv['id']}/messages")->assertOk()->assertJsonPath('data.0.body', 'Mon retrait est bloqué');
        $reply = $this->postJson("/api/support/chat/{$conv['id']}/messages", ['body' => 'Nous regardons tout de suite.'])->assertCreated()->json();
        $this->assertSame($admin->id, ChatMessage::find($reply['id'])->agent_id);
        $this->assertSame(SupportChat::user()->id, ChatMessage::find($reply['id'])->sender_id);

        // Le client appelle le support : la console le voit sonner et décroche
        $this->app['auth']->forgetGuards();
        $this->actingAs($client, 'sanctum');
        $call = $this->postJson("/api/chats/{$conv['id']}/calls", ['offer' => 'v=0 sdp'])->assertCreated()->json();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/support/chat/calls/incoming')->assertOk()->assertJsonPath('call.id', $call['id']);
        $this->postJson("/api/support/chat/calls/{$call['id']}/accept", ['answer' => 'v=0 answer'])->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson("/api/support/chat/calls/{$call['id']}/end")->assertOk();

        // Discussion entre deux clients : visible par l'admin (lecture seule, auditée)
        $this->app['auth']->forgetGuards();
        $this->actingAs($client, 'sanctum');
        $c2 = ChatConversation::between($client, $friend);
        $this->postJson("/api/chats/{$c2->id}/messages", ['body' => 'Envoie-moi 5000'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/admin/chat-monitor?q=242061999002')->assertOk()->assertJsonPath('data.0.id', $c2->id);
        $this->getJson("/api/admin/chat-monitor/{$c2->id}/messages")->assertOk()->assertJsonPath('data.0.body', 'Envoie-moi 5000');
        $this->getJson('/api/admin/chat-monitor/calls')->assertOk()->assertJsonPath('data.0.agent', $admin->full_name);
        $this->assertTrue(AuditLog::where('action', 'chat.monitor_view')->exists());
    }
}

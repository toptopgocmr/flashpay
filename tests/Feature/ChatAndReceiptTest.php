<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ChatAndReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    protected function client(string $phone, string $name): User
    {
        $u = User::create(['full_name' => $name, 'phone' => $phone, 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 5000, 'currency' => 'XAF', 'country' => 'CG']);
        return $u->fresh();
    }

    public function test_two_users_chat_with_text_image_and_video(): void
    {
        $a = $this->client('242067700001', 'Alice Mabiala');
        $b = $this->client('242057700002', 'Bruno Okemba');
        $c = $this->client('242067700003', 'Carine Tchicaya');

        $this->actingAs($a, 'sanctum');
        $conv = $this->postJson('/api/chats', ['phone' => '057700002'])->assertOk()->json();
        $this->assertSame('Bruno Okemba', $conv['user']['name']);
        $this->assertSame($conv['id'], $this->postJson('/api/chats', ['phone' => '+242057700002'])->json('id')); // même discussion
        $this->postJson('/api/chats', ['phone' => '069999999'])->assertNotFound();

        $this->postJson("/api/chats/{$conv['id']}/messages", ['body' => 'Mbote Bruno !'])->assertCreated();
        $img = UploadedFile::fake()->image('capture.png', 200, 120);
        $m = $this->post("/api/chats/{$conv['id']}/messages", ['file' => $img, 'body' => 'La capture'], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame('image', $m['type']);
        $vid = UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 4000));
        $v = $this->post("/api/chats/{$conv['id']}/messages", ['file' => $vid], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame('video', $v['type']);
        $big = UploadedFile::fake()->create('long.mp4', 6000, 'video/mp4');
        $this->post("/api/chats/{$conv['id']}/messages", ['file' => $big], ['Accept' => 'application/json'])->assertStatus(422);

        // Bruno : 3 non lus, puis lecture
        $this->actingAs($b, 'sanctum');
        $list = $this->getJson('/api/chats')->assertOk()->json();
        $this->assertSame(3, $list['unread_total']);
        $this->assertSame('Alice Mabiala', $list['data'][0]['user']['name']);
        $msgs = $this->getJson("/api/chats/{$conv['id']}/messages")->assertOk()->json('data');
        $this->assertCount(3, $msgs);
        $this->assertFalse($msgs[0]['mine']);
        $this->assertSame(0, $this->getJson('/api/chats')->json('unread_total'));
        $this->get("/api/chats/messages/{$m['id']}/file")->assertOk()->assertHeader('Content-Type', 'image/png');
        $url = $this->getJson("/api/chats/messages/{$v['id']}/link")->assertOk()->json('url');
        $this->get($url)->assertOk();

        // Carine n'a pas accès
        $this->actingAs($c, 'sanctum');
        $this->getJson("/api/chats/{$conv['id']}/messages")->assertForbidden();
        $this->get("/api/chats/messages/{$m['id']}/file", ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_receipt_link_is_owner_only_and_renders(): void
    {
        $a = $this->client('242067700011', 'Alice Mabiala');
        $b = $this->client('242067700012', 'Bruno Okemba');
        $tx = Transaction::create([
            'reference' => 'FP-RECUTEST01', 'type' => 'p2p', 'source_rail' => 'wallet', 'destination_rail' => 'peex',
            'source_wallet_id' => $a->wallet->id, 'destination_account' => '+242055212223', 'amount' => 1000, 'fee' => 10,
            'currency' => 'XAF', 'status' => 'successful', 'initiated_by' => $a->id, 'meta' => ['beneficiary_name' => 'Jean Moukala'],
        ]);

        $this->actingAs($b, 'sanctum');
        $this->getJson("/api/transactions/{$tx->id}/receipt")->assertNotFound();
        $this->getJson("/api/transactions/{$tx->id}")->assertNotFound();

        $this->actingAs($a, 'sanctum');
        $this->getJson("/api/transactions/{$tx->id}")->assertOk()->assertJsonPath('status_label', 'Réussie');
        $url = $this->getJson("/api/transactions/{$tx->id}/receipt")->assertOk()->json('url');
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('FP-RECUTEST01', $html);
        $this->assertStringContainsString('Jean Moukala', $html);
        $this->assertStringContainsString('1 010 XAF', $html);
        $this->get('/r/FP-RECUTEST01')->assertForbidden(); // sans signature
    }
}

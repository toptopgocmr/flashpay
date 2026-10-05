<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessageTranslation;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTranslationTest extends TestCase
{
    protected function mk(string $phone, string $lang): User
    {
        $u = User::create(['full_name' => "User {$phone}", 'phone' => $phone, 'password' => 'secret123', 'language' => $lang]);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        return $u;
    }

    public function test_each_participant_reads_in_his_language(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.translation.driver' => 'mymemory', 'flashpay.translation.enabled' => true]);
        Http::fake([
            'api.mymemory.translated.net/*' => function ($request) {
                $map = ['en|fr' => 'Bonjour mon ami', 'fr|en' => 'See you tomorrow'];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                return Http::response(['responseStatus' => 200, 'responseData' => ['translatedText' => $map[$q['langpair'] ?? ''] ?? '?']]);
            },
        ]);
        $en = $this->mk('242061888001', 'en');
        $fr = $this->mk('242061888002', 'fr');
        $c = ChatConversation::between($en, $fr);

        $this->actingAs($en, 'sanctum');
        $sent = $this->postJson("/api/chats/{$c->id}/messages", ['body' => 'Hello my friend'])->assertCreated()->json();
        $this->assertSame('en', $sent['lang']);
        $this->assertNull($sent['translation']); // l'auteur voit son texte

        $this->actingAs($fr, 'sanctum');
        $this->getJson("/api/chats/{$c->id}/messages")->assertOk()
            ->assertJsonPath('data.0.body', 'Hello my friend')
            ->assertJsonPath('data.0.translation', 'Bonjour mon ami');
        $this->getJson("/api/chats/{$c->id}/messages?translate=0")->assertJsonPath('data.0.translation', null);

        $this->postJson("/api/chats/{$c->id}/messages", ['body' => 'À demain'])->assertCreated();
        $this->actingAs($en, 'sanctum');
        $this->getJson("/api/chats/{$c->id}/messages")->assertJsonPath('data.1.translation', 'See you tomorrow');
        $this->assertSame(2, ChatMessageTranslation::count()); // traductions en cache
    }
}

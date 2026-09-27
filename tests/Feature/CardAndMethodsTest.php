<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CardAndMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    protected function client(string $phone, int $balance = 0): User
    {
        $u = User::create(['full_name' => 'Client ' . $phone, 'phone' => $phone, 'password' => 'x']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        return $u->fresh();
    }

    protected function token(array $json): string
    {
        return basename(parse_url($json['checkout_url'], PHP_URL_PATH));
    }

    public function test_methods_include_card_nfc_bank_send(): void
    {
        $this->actingAs($this->client('242061111111'), 'sanctum');
        $dep = collect($this->getJson('/api/pay/methods?country=CG&operation=deposit')->json('methods'))->keyBy('key');
        $this->assertTrue($dep['card']['available']);
        $pay = collect($this->getJson('/api/pay/methods?country=CG&operation=pay')->json('methods'))->keyBy('key');
        $this->assertTrue($pay['nfc']['available']);
        $send = collect($this->getJson('/api/pay/methods?country=CG&operation=send')->json('methods'))->keyBy('key');
        $this->assertTrue($send['card']['available']);
        $this->assertFalse($send['bank']['available']);
        $wd = collect($this->getJson('/api/pay/methods?country=CG&operation=withdraw')->json('methods'))->keyBy('key');
        $this->assertFalse($wd['bank']['available']);
    }

    public function test_card_deposit_success_and_decline(): void
    {
        $c = $this->client('242061111111');
        $this->actingAs($c, 'sanctum');

        $q = $this->postJson('/api/pay/quote', ['operation' => 'deposit', 'source' => 'card', 'amount' => 10000])->assertOk();
        $this->assertSame(250, $q->json('fee'));

        $r = $this->postJson('/api/pay/deposit', ['method' => 'card', 'amount' => 10000])->assertStatus(202)->json();
        $this->assertSame('awaiting_card', $r['stage']);
        $t = $this->token($r);

        $this->get("/api/card-checkout/{$t}")->assertOk()->assertSee('Payer');
        $this->post("/api/card-checkout/{$t}", ['card_number' => '1234', 'expiry' => '12/30', 'cvc' => '123'])->assertStatus(422);
        $this->post("/api/card-checkout/{$t}", ['card_number' => '4242 4242 4242 4242', 'expiry' => '12/30', 'cvc' => '123', 'action' => 'pay'])->assertOk()->assertSee('Paiement accepté');
        $this->assertSame(10000, $c->wallet->fresh()->balance);
        $this->assertSame('Visa •••• 4242', Transaction::find($r['id'])->source_account);
        // Idempotent
        $this->post("/api/card-checkout/{$t}", ['card_number' => '4242424242424242', 'expiry' => '12/30', 'cvc' => '123'])->assertOk();
        $this->assertSame(10000, $c->wallet->fresh()->balance);

        $r2 = $this->postJson('/api/pay/deposit', ['method' => 'card', 'amount' => 5000])->json();
        $this->post('/api/card-checkout/' . $this->token($r2), ['card_number' => '4000000000000002', 'expiry' => '12/30', 'cvc' => '123'])->assertSee('refusée');
        $this->assertSame('failed', Transaction::find($r2['id'])->status);
        $this->assertSame(10000, $c->wallet->fresh()->balance);
    }

    public function test_send_paid_by_card_to_flashpay_user(): void
    {
        $a = $this->client('242061111111');
        $b = $this->client('242062222222');
        $this->actingAs($a, 'sanctum');

        $r = $this->postJson('/api/pay/transfer', ['source' => 'card', 'destination_phone' => '+242062222222', 'amount' => 5000])->assertStatus(202)->json();
        $this->post('/api/card-checkout/' . $this->token($r), ['card_number' => '5555555555554444', 'expiry' => '11/29', 'cvc' => '999'])->assertSee('Mastercard');
        $this->assertSame(5000, $b->wallet->fresh()->balance);
        $this->assertSame('successful', Transaction::find($r['id'])->status);

        $admin = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum');
        $this->assertSame(1, $this->getJson('/api/admin/transactions?channel=card_transfer')->json('total'));
    }
}

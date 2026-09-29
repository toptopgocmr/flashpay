<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sans callback PEEX ni planificateur (cas Railway) : la route de suivi que
 * l'app interroge vérifie le statut auprès de PEEX et crédite le wallet.
 */
class PeexStatusPollingTest extends TestCase
{
    protected string $peexStatus = 'pending';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.peex.secret_key' => 'k', 'flashpay.peex.verify_accounts' => false, 'flashpay.peex.check_balance' => false]);
        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $data = $r->data();
            if (str_ends_with($path, 'collection/request_payment')) {
                return Http::response(['id' => 1, 'track_id' => $data['track_id'], 'status' => 'pending']);
            }
            if (str_contains($path, 'all_requests')) {
                return Http::response([['id' => 1, 'track_id' => $data['track_id'] ?? null, 'status' => $this->peexStatus, 'payment_proof' => 'MTN 8334311488']]);
            }
            return Http::response(['ok' => true, 'is_activated' => true]);
        });
    }

    public function test_status_poll_confirms_collect_and_credits_wallet(): void
    {
        $u = User::create(['full_name' => 'Client P', 'phone' => '242067601919', 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        $this->actingAs($u, 'sanctum');

        $r = $this->postJson('/api/pay/deposit', ['phone' => '+242067601919', 'amount' => 100])->json();
        $id = $r['transaction']['id'] ?? $r['id'] ?? null;
        $this->assertNotNull($id, json_encode($r));
        $this->assertSame(0, $u->wallet->fresh()->balance);

        $this->peexStatus = 'paid'; // le client a validé sur son téléphone
        \Illuminate\Support\Facades\Cache::flush();
        $s = $this->getJson("/api/pay/transactions/{$id}/status")->assertOk()->json();

        $this->assertSame(100, $u->wallet->fresh()->balance, json_encode($s));

        // La console voit le paiement entrant réussi dans ses notifications
        $n = \App\Models\AppNotification::where('audience', 'admin')->where('type', 'payment_in')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('Paiement entrant réussi', $n->title);
        $this->assertSame($id, $n->data['transaction_id']);
    }
}

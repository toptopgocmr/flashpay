<?php

namespace Tests\Feature;

use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sécurité des parcours PEEX :
 *  - vérification des comptes (Verify Wallet) et des soldes PEEX avant débit ;
 *  - transfert mobile -> mobile autorisé sur tous les opérateurs (même opérateur inclus) ;
 *  - issue incertaine (timeout) = statut vérifié, jamais un échec présumé ;
 *  - échec de versement confirmé auprès de PEEX puis remboursement automatique du payeur.
 */
class PeexSafetyTest extends TestCase
{
    /** État simulé côté PEEX */
    protected array $peex = [
        'disbursement_solde' => 1_000_000,
        'invalid_accounts' => [],
        'disburse' => 'new',          // new | timeout
        'status' => [],               // track_id => statut renvoyé par all_requests
        'calls' => [],
        'collect_me' => 200,          // code HTTP renvoyé par collection/me
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.peex.verify_accounts' => true, 'flashpay.peex.check_balance' => true, 'flashpay.peex.secret_key' => 'test-key']);

        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $data = $r->data();
            $this->peex['calls'][] = [$r->method(), $path, $data];

            return match (true) {
                str_ends_with($path, 'clients/verify_wallet') => in_array($data['accountNumber'] ?? '', $this->peex['invalid_accounts'], true)
                    ? Http::response(['error' => ['statusCode' => 404, 'message' => 'Account not found on the provider network']], 404)
                    : Http::response(['valid' => true, 'accountTitle' => 'JEAN MOUKALA', 'accountStatus' => 'ACTIVE', 'accountType' => 'MOBILE_WALLET']),
                str_ends_with($path, 'collection/me') => $this->peex['collect_me'] === 200
                    ? Http::response(['name' => 'flashpay', 'is_activated' => true, 'collect_solde' => 0])
                    : Http::response($this->peex['collect_me'] === 403 ? '<html>403 Forbidden</html>' : ['message' => 'Server Error'], $this->peex['collect_me']),
                str_ends_with($path, 'disbursement/me') => Http::response(['name' => 'flashpay', 'is_activated' => true, 'disbursement_solde' => $this->peex['disbursement_solde'], 'mtn_fees' => 1, 'orange_fees' => 1]),
                str_ends_with($path, 'clients/me') => Http::response(['name' => 'flashpay', 'is_activated' => true, 'solde' => 1_000_000]),
                str_ends_with($path, 'collection/request_payment') => Http::response(['id' => 1, 'track_id' => $data['track_id'], 'status' => 'pending']),
                str_ends_with($path, 'disbursement/request_payment') => $this->peex['disburse'] === 'timeout' && ! str_contains($data['track_id'], '-R')
                    ? Http::failedConnection('cURL error 28: Operation timed out')
                    : Http::response(['request' => ['id' => 2, 'track_id' => $data['track_id'], 'status' => 'new']]),
                str_contains($path, 'all_requests') => isset($this->peex['status'][$data['track_id'] ?? ''])
                    ? Http::response([['id' => 3, 'track_id' => $data['track_id'], 'status' => $this->peex['status'][$data['track_id']], 'payment_proof' => 'test']])
                    : Http::response([]),
                default => Http::response(['ok' => true]),
            };
        });
    }

    protected function client(string $phone, int $balance = 0): User
    {
        $u = User::create(['full_name' => 'Client ' . $phone, 'phone' => $phone, 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        return $u->fresh();
    }

    protected function peexCallback(string $service, string $trackId, string $status)
    {
        return $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('peex:peex_callback')])
            ->postJson("/api/webhooks/peex/{$service}", [['track_id' => $trackId, 'status' => $status, 'payment_proof' => 'cb']])
            ->assertOk();
    }

    public function test_mobile_to_mobile_is_allowed_on_all_operators_and_wallet_withdrawal_too(): void
    {
        $c = $this->client('242061000501', 50000);
        $this->actingAs($c, 'sanctum');

        $q = $this->postJson('/api/pay/quote', ['operation' => 'transfer', 'source' => 'mobile', 'source_phone' => '+242061000501', 'destination_phone' => '+242066000502', 'deliver_to' => 'mobile', 'amount' => 1000])->assertOk();
        $this->assertTrue($q->json('available'), implode(' ', $q->json('problems'))); // MTN -> MTN

        // MTN -> Airtel : autorisé, titulaire vérifié affiché
        $q = $this->postJson('/api/pay/quote', ['operation' => 'transfer', 'source' => 'mobile', 'source_phone' => '+242061000501', 'destination_phone' => '+242055000502', 'deliver_to' => 'mobile', 'amount' => 1000])->assertOk();
        $this->assertTrue($q->json('available'), implode(' ', $q->json('problems')));
        $this->assertSame('JEAN MOUKALA', $q->json('destination.verified_name'));

        // Retrait wallet -> MTN : autorisé
        $q = $this->postJson('/api/pay/quote', ['operation' => 'withdraw', 'destination_phone' => '+242066000503', 'amount' => 1000])->assertOk();
        $this->assertTrue($q->json('available'), implode(' ', $q->json('problems')));
    }

    public function test_inactive_beneficiary_account_blocks_before_any_debit(): void
    {
        $c = $this->client('242061000511', 50000);
        $this->peex['invalid_accounts'] = ['055000512'];
        $this->actingAs($c, 'sanctum');

        $q = $this->postJson('/api/pay/quote', ['operation' => 'withdraw', 'destination_phone' => '+242055000512', 'amount' => 1000])->assertOk();
        $this->assertFalse($q->json('available'));
        $this->assertStringContainsString("n'est pas un compte mobile money actif", implode(' ', $q->json('problems')));

        $this->postJson('/api/pay/withdraw', ['phone' => '+242055000512', 'amount' => 1000])->assertStatus(422);
        $this->assertSame(50000, $c->wallet->fresh()->balance);
        $this->assertSame(0, PeexRequest::count());
    }

    public function test_insufficient_peex_balance_blocks_before_any_debit(): void
    {
        $c = $this->client('242061000521', 50000);
        $this->peex['disbursement_solde'] = 500;
        $this->actingAs($c, 'sanctum');

        $q = $this->postJson('/api/pay/quote', ['operation' => 'withdraw', 'destination_phone' => '+242055000522', 'amount' => 1000])->assertOk();
        $this->assertFalse($q->json('available'));

        $this->postJson('/api/pay/withdraw', ['phone' => '+242055000522', 'amount' => 1000])->assertStatus(422);
        $this->assertSame(50000, $c->wallet->fresh()->balance);
        $this->assertSame(0, PeexRequest::count());
    }

    public function test_timeout_is_verified_not_refunded(): void
    {
        $c = $this->client('242061000531', 50000);
        $this->peex['disburse'] = 'timeout';
        $this->actingAs($c, 'sanctum');

        $tx = $this->postJson('/api/pay/withdraw', ['phone' => '+242055000532', 'amount' => 1000])->assertStatus(202)->json();
        $req = PeexRequest::where('service', 'disbursement')->firstOrFail();
        $this->assertSame('unknown', $req->status);
        $this->assertNull($req->finalized_at);
        $this->assertSame('awaiting_destination', Transaction::find($tx['id'])->stage);
        $balanceAfterDebit = $c->wallet->fresh()->balance;
        $this->assertLessThan(50000, $balanceAfterDebit); // débité, PAS remboursé

        // PEEX avait bien reçu la demande et l'a payée : la transaction réussit
        $this->peex['status'][$req->track_id] = 'paid';
        Artisan::call('peex:sync');
        $this->assertSame('successful', Transaction::find($tx['id'])->status);
        $this->assertSame($balanceAfterDebit, $c->wallet->fresh()->balance);
    }

    public function test_unknown_request_never_received_by_peex_is_refunded_after_grace_period(): void
    {
        $c = $this->client('242061000541', 50000);
        $this->peex['disburse'] = 'timeout';
        $this->actingAs($c, 'sanctum');

        $tx = $this->postJson('/api/pay/withdraw', ['phone' => '+242055000542', 'amount' => 1000])->assertStatus(202)->json();
        $req = PeexRequest::where('service', 'disbursement')->firstOrFail();

        Artisan::call('peex:sync'); // introuvable mais dans le délai de grâce : on attend
        $this->assertSame('processing', Transaction::find($tx['id'])->status);

        $req->forceFill(['created_at' => now()->subMinutes(15)])->save();
        Artisan::call('peex:sync'); // toujours introuvable après 10 min : jamais reçue -> remboursement
        $this->assertSame('reversed', Transaction::find($tx['id'])->status);
        $this->assertSame(50000, $c->wallet->fresh()->balance);
    }

    public function test_mobile_to_mobile_failed_payout_is_confirmed_then_payer_refunded(): void
    {
        $c = $this->client('242061000551', 0);
        $this->actingAs($c, 'sanctum');

        $tx = $this->postJson('/api/pay/transfer', [
            'source' => 'mobile', 'source_phone' => '+242061000551',
            'destination_phone' => '+242055000552', 'deliver_to' => 'mobile', 'amount' => 1000,
        ])->assertStatus(202)->json();
        $collect = PeexRequest::where('service', 'collect')->firstOrFail();

        // Le client valide sur son téléphone MTN -> versement Airtel lancé
        $this->peexCallback('collect', $collect->track_id, 'paid');
        $payout = PeexRequest::where('track_id', 'like', '%-D1')->firstOrFail();
        $this->assertSame('+242055000552', $payout->phone);

        // Callback « failed » NON confirmé par all_requests (encore pending) : pas de remboursement
        $this->peex['status'][$payout->track_id] = 'pending';
        $this->peexCallback('disbursement', $payout->track_id, 'failed');
        $this->assertNull($payout->fresh()->finalized_at);
        $this->assertSame(0, PeexRequest::where('track_id', 'like', '%-R%')->count());

        // Échec confirmé par PEEX : remboursement lancé vers le numéro MTN du payeur
        $this->peex['status'][$payout->track_id] = 'failed';
        $this->peexCallback('disbursement', $payout->track_id, 'failed');
        $refund = PeexRequest::where('track_id', 'like', '%-R1')->firstOrFail();
        $this->assertSame('+242061000551', $refund->phone);
        $t = Transaction::find($tx['id']);
        $this->assertSame(['processing', 'awaiting_refund'], [$t->status, $t->stage]);
        $this->assertSame((int) ($t->amount + $t->fee), $refund->amount);

        // Remboursement payé -> transaction remboursée
        $this->peexCallback('disbursement', $refund->track_id, 'paid');
        $this->assertSame('reversed', Transaction::find($tx['id'])->status);

        // Callback tardif contradictoire : ignoré (demande déjà finalisée)
        $this->peexCallback('disbursement', $payout->track_id, 'paid');
        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertSame('reversed', Transaction::find($tx['id'])->status);
    }

    public function test_collection_me_error_does_not_block_mobile_payment_unless_access_denied(): void
    {
        $c = $this->client('242061000591', 0);
        $this->actingAs($c, 'sanctum');
        $body = ['operation' => 'transfer', 'source' => 'mobile', 'source_phone' => '+242067601919', 'destination_phone' => '+242055212223', 'deliver_to' => 'mobile', 'amount' => 50];

        // Fiche collection/me en erreur 500 : contrôle ignoré, l'envoi MTN -> Airtel reste possible
        $this->peex['collect_me'] = 500;
        $q = $this->postJson('/api/pay/quote', $body)->assertOk();
        $this->assertTrue($q->json('available'), implode(' ', $q->json('problems')));

        // Accès refusé (pare-feu PEEX) : bloqué
        \Illuminate\Support\Facades\Cache::flush();
        $this->peex['collect_me'] = 403;
        $q = $this->postJson('/api/pay/quote', $body)->assertOk();
        $this->assertFalse($q->json('available'));
    }
}

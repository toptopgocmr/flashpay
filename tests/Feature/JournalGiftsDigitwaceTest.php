<?php

namespace Tests\Feature;

use App\Models\DigitwaceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\TransactionPresenter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class JournalGiftsDigitwaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    protected function client(string $phone, string $name, int $balance = 10000): User
    {
        $u = User::create(['full_name' => $name, 'phone' => $phone, 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        return $u->fresh('wallet');
    }

    protected function tx(array $a): Transaction
    {
        return Transaction::create($a + ['reference' => 'FP-' . Str::upper(Str::random(12)), 'amount' => 100, 'fee' => 0, 'currency' => 'XAF', 'status' => 'successful', 'initiated_by' => 1]);
    }

    public function test_journal_labels_directions_and_counterparties(): void
    {
        $a = $this->client('242061111111', 'ALICE MBEMBA');
        $b = $this->client('242062222222', 'BOB NGOMA');
        $wa = $a->wallet->id;
        $wb = $b->wallet->id;

        $mobileDeposit = $this->tx(['type' => 'cash_in', 'source_rail' => 'peex', 'destination_rail' => 'wallet', 'source_account' => '242067601919', 'destination_wallet_id' => $wa, 'initiated_by' => $a->id, 'meta' => ['payer_verified_name' => 'ALICE M.']]);
        $cardDeposit = $this->tx(['type' => 'cash_in', 'source_rail' => 'card', 'destination_rail' => 'wallet', 'destination_wallet_id' => $wa, 'initiated_by' => $a->id]);
        $agentDeposit = $this->tx(['type' => 'cash_in', 'source_rail' => 'wallet', 'destination_rail' => 'wallet', 'source_wallet_id' => $wb, 'destination_wallet_id' => $wa, 'initiated_by' => $b->id, 'meta' => ['channel' => 'agent', 'agent_name' => 'BOB NGOMA']]);
        $p2p = $this->tx(['type' => 'p2p', 'source_rail' => 'wallet', 'destination_rail' => 'wallet', 'source_wallet_id' => $wb, 'destination_wallet_id' => $wa, 'initiated_by' => $b->id, 'meta' => ['sender_name' => 'BOB NGOMA', 'beneficiary_name' => 'ALICE MBEMBA']]);
        $withdraw = $this->tx(['type' => 'withdrawal', 'source_rail' => 'wallet', 'destination_rail' => 'peex', 'source_wallet_id' => $wa, 'destination_account' => '242061111111', 'initiated_by' => $a->id, 'fee' => 1]);
        $sendMobile = $this->tx(['type' => 'p2p', 'source_rail' => 'peex', 'destination_rail' => 'peex', 'source_account' => '242067601919', 'destination_account' => '242057563644', 'initiated_by' => $a->id, 'meta' => ['beneficiary_name' => 'CARINE']]);

        $ids = [$wa];
        $p = fn ($t) => TransactionPresenter::present($t->fresh(), $ids, $a->id);

        $this->assertSame('Recharge compte FlashPay', $p($mobileDeposit)['label']);
        $this->assertSame('credit', $p($mobileDeposit)['direction']);
        $this->assertSame('Mobile → Wallet', $p($mobileDeposit)['channel']);
        $this->assertSame('ALICE M.', $p($mobileDeposit)['counterparty_name']);
        $this->assertSame('Carte bancaire → Wallet', $p($cardDeposit)['channel']);
        $this->assertSame('Cash → Wallet', $p($agentDeposit)['channel']);
        $this->assertSame('Agent BOB NGOMA', $p($agentDeposit)['counterparty_name']);
        $this->assertSame('Recharge compte FlashPay', $p($agentDeposit)['label']);

        $this->assertSame('Transfert entrant', $p($p2p)['label']);
        $this->assertSame('in', $p($p2p)['flow']);
        $this->assertSame('Wallet → Wallet', $p($p2p)['channel']);
        $this->assertSame('BOB NGOMA', $p($p2p)['counterparty_name']);

        $this->assertSame('Retrait', $p($withdraw)['label']);
        $this->assertSame('debit', $p($withdraw)['direction']);
        $this->assertSame(-101, $p($withdraw)['signed_amount']);

        $this->assertSame('Transfert sortant', $p($sendMobile)['label']);
        $this->assertSame('CARINE', $p($sendMobile)['counterparty_name']);
        $this->assertSame('Mobile → Mobile', $p($sendMobile)['channel']);

        // Côté Bob, le même P2P est un transfert sortant (débit) vers Alice
        $pb = TransactionPresenter::present($p2p->fresh(), [$wb], $b->id);
        $this->assertSame('Transfert sortant', $pb['label']);
        $this->assertSame('ALICE MBEMBA', $pb['counterparty_name']);

        // API : liste paginée enrichie
        $res = $this->actingAs($a, 'sanctum')->getJson('/api/transactions')->assertOk();
        $first = collect($res->json('data'))->firstWhere('id', $mobileDeposit->id);
        $this->assertSame('Recharge compte FlashPay', $first['label']);
        $this->assertSame('↓', $first['arrow']);
        $this->actingAs($a, 'sanctum')->getJson('/api/transactions/' . $withdraw->id)->assertOk()->assertJsonPath('direction', 'debit');
    }

    public function test_pending_mobile_validation_expires_then_late_success_reopens(): void
    {
        config(['flashpay.peex.validation_timeout_seconds' => 60]);
        $a = $this->client('242063333333', 'CLAIRE', 0);
        $tx = $this->tx(['type' => 'cash_in', 'source_rail' => 'peex', 'destination_rail' => 'wallet', 'source_account' => '242063333333',
            'destination_wallet_id' => $a->wallet->id, 'initiated_by' => $a->id, 'status' => 'processing', 'stage' => 'awaiting_source']);
        $tx->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();

        $res = $this->actingAs($a, 'sanctum')->getJson('/api/pay/transactions/' . $tx->id . '/status');
        $res->assertOk()->assertJsonPath('status', 'failed');
        $this->assertStringContainsString('Délai dépassé', $res->json('message'));

        // Validation tardive confirmée par PEEX : rouverte et créditée
        app(\App\Services\Peex\PendingTimeoutService::class)->reopenAfterLateSuccess($tx->fresh());
        $this->assertSame('successful', $tx->fresh()->status);
        $this->assertSame(100, (int) $a->wallet->fresh()->balance);
    }

    public function test_fresh_pending_has_countdown(): void
    {
        $a = $this->client('242064444444', 'DAN', 0);
        $tx = $this->tx(['type' => 'cash_in', 'source_rail' => 'peex', 'destination_rail' => 'wallet', 'source_account' => '242064444444',
            'destination_wallet_id' => $a->wallet->id, 'initiated_by' => $a->id, 'status' => 'processing', 'stage' => 'awaiting_source']);
        $res = $this->actingAs($a, 'sanctum')->getJson('/api/pay/transactions/' . $tx->id . '/status')->assertOk();
        $this->assertSame('processing', $res->json('status'));
        $this->assertGreaterThan(150, $res->json('validation_seconds_left'));
    }

    public function test_gifts_present_share_and_cancel(): void
    {
        $a = $this->client('242065555555', 'EMMA', 5000);
        $svc = app(\App\Services\Client\GiftService::class);
        $env = $svc->create($a, ['mode' => 'random', 'amount' => 1000, 'shares' => 4, 'occasion' => 'anniversaire', 'message' => 'Joyeux anniv']);
        $this->assertSame(4000, (int) $a->wallet->fresh()->balance);

        $d = $this->actingAs($a, 'sanctum')->getJson('/api/gifts')->assertOk()->json();
        $this->assertSame('Cagnotte surprise', $d['sent'][0]['mode_label']);
        $this->assertStringContainsString('/g/' . $env->code, $d['sent'][0]['share_text']);
        $this->assertSame(1, $d['summary']['active_sent']);

        $this->actingAs($a, 'sanctum')->postJson('/api/gifts/' . $env->code . '/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(5000, (int) $a->wallet->fresh()->balance);
    }

    public function test_money_request_channels_and_share_text(): void
    {
        $a = $this->client('242066666666', 'FRED KOUBA');
        $this->client('242067777777', 'GINA LOEMBA');
        $r = $this->actingAs($a, 'sanctum')->postJson('/api/money-requests', ['phone' => '+242067777777', 'amount' => 2500, 'note' => '  part   du taxi ', 'channel' => 'whatsapp'])->assertCreated()->json();
        $this->assertSame('whatsapp', $r['channel']);
        $this->assertSame('part du taxi', $r['note']);
        $this->assertStringContainsString('Bonjour GINA', $r['share_text']);
        $this->assertStringContainsString('2 500 XAF', $r['share_text']);
        $this->assertStringContainsString('/d/' . $r['reference'], $r['share_text']);
        $this->get('/d/' . $r['reference'])->assertOk()->assertSee('FRED KOUBA');
    }

    public function test_digitwace_payout_and_signed_webhook(): void
    {
        config([
            'flashpay.rails.digitwace.enabled' => true,
            'flashpay.digitwace.enabled' => true,
            'flashpay.digitwace.public_key' => 'pub', 'flashpay.digitwace.private_key' => 'priv',
            'flashpay.digitwace.base_url' => 'https://wace.test/api/',
            'flashpay.digitwace.payer_map' => ['SN:ORANGE' => 'PAY-SN-OR'],
            'flashpay.digitwace.webhook_secret' => 'whsec',
        ]);
        $calls = 0;
        $status = 'PENDING';
        Http::fake(function ($req) use (&$calls, &$status) {
            $calls++;
            $u = $req->url();
            return match (true) {
                str_contains($u, 'get-token') => Http::response(['code' => 2000, 'token' => 'tok']),
                str_contains($u, 'sender/create') => Http::response(['code' => 2000, 'data' => ['senderCode' => 'S1']]),
                str_contains($u, 'beneficiary/create') => Http::response(['code' => 2000, 'data' => ['beneficiaryCode' => 'B1']]),
                str_contains($u, 'transaction/wallet') => Http::response(['code' => 2000, 'data' => ['transactionCode' => 'WT-1']]),
                str_contains($u, 'transaction/confirm') => Http::response(['code' => 2000, 'data' => ['status' => 'PENDING']]),
                str_contains($u, 'transaction/status') => Http::response(['code' => 2000, 'data' => ['status' => $status]]),
                default => Http::response(['code' => 1001], 404),
            };
        });

        $a = $this->client('242068888888', 'HUGO', 10000);
        $tx = $this->tx(['type' => 'p2p', 'source_rail' => 'wallet', 'destination_rail' => 'digitwace', 'source_wallet_id' => $a->wallet->id,
            'destination_account' => '221771234567', 'initiated_by' => $a->id, 'status' => 'processing', 'amount' => 1000,
            'meta' => ['destination_country' => 'SN', 'destination_operator' => 'ORANGE', 'beneficiary_name' => 'IBA NDIAYE', 'sender_name' => 'HUGO', 'sender_phone' => '242068888888']]);

        $r = app(\App\Services\Digitwace\DigitwaceConnector::class)->disburse('221771234567', 1000, 'XAF', $tx->reference);
        $this->assertSame('pending', $r['status']);
        $this->assertSame('WT-1', $r['external_ref']);
        $tx->update(['stage' => 'awaiting_destination', 'destination_external_ref' => 'WT-1']);
        $this->assertSame('S1', \App\Models\DigitwaceParty::where('kind', 'sender')->value('code'));

        // Webhook mal signé : refusé
        $body = json_encode(['transactionCode' => 'WT-1', 'status' => 'SUCCESS']);
        $this->call('POST', '/api/webhooks/digitwace', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WACE_SIGNATURE' => 'bad'], $body)->assertStatus(401);

        // Webhook signé, mais l'API dit encore PENDING : rien n'est appliqué
        $sig = hash_hmac('sha256', $body, 'whsec');
        $this->call('POST', '/api/webhooks/digitwace', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WACE_SIGNATURE' => $sig], $body)->assertOk();
        $this->assertSame('processing', $tx->fresh()->status);

        // API confirme : transaction réussie
        $status = 'SUCCESS';
        $this->call('POST', '/api/webhooks/digitwace', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WACE_SIGNATURE' => 'sha256=' . $sig], $body)->assertOk();
        $this->assertSame('successful', $tx->fresh()->status);
        $this->assertNotNull(DigitwaceRequest::where('reference', $tx->reference)->value('finalized_at'));

        // Second versement : expéditeur / bénéficiaire en cache, plus de login (jeton en cache)
        $before = $calls;
        $tx2 = $this->tx(['type' => 'p2p', 'source_rail' => 'wallet', 'destination_rail' => 'digitwace', 'source_wallet_id' => $a->wallet->id,
            'destination_account' => '221771234567', 'initiated_by' => $a->id, 'status' => 'processing', 'amount' => 500, 'meta' => $tx->meta]);
        app(\App\Services\Digitwace\DigitwaceConnector::class)->disburse('221771234567', 500, 'XAF', $tx2->reference);
        $this->assertSame(2, $calls - $before, 'Wallet + Confirm seulement');
    }

    public function test_notification_has_both_parties_and_detail(): void
    {
        $a = $this->client('242069990001', 'JEAN ENVOYEUR', 5000);
        $b = $this->client('242069990002', 'MARIE RECEVEUSE', 0);
        $tx = app(\App\Services\SwitchService::class)->process([
            'type' => 'p2p', 'scope' => 'national', 'source_rail' => 'wallet', 'source_wallet_id' => $a->wallet->id,
            'destination_rail' => 'wallet', 'destination_wallet_id' => $b->wallet->id, 'amount' => 1000, 'currency' => 'XAF',
            'initiated_by' => $a->id, 'meta' => ['sender_name' => 'JEAN ENVOYEUR', 'beneficiary_name' => 'MARIE RECEVEUSE'],
        ]);
        $this->assertSame('successful', $tx->status);

        $n = \App\Models\AppNotification::where('user_id', $b->id)->where('type', 'money_received')->firstOrFail();
        $this->assertStringContainsString('Expéditeur : JEAN ENVOYEUR', $n->body);
        $this->assertStringContainsString('Bénéficiaire : MARIE RECEVEUSE', $n->body);
        $this->assertTrue((bool) $n->sound);
        $sent = \App\Models\AppNotification::where('user_id', $a->id)->where('type', 'money_sent')->firstOrFail();
        $this->assertStringContainsString('Bénéficiaire : MARIE RECEVEUSE', $sent->body);

        // Polling : seules les notifications plus récentes que after_id
        $this->actingAs($b, 'sanctum')->getJson('/api/notifications?after_id=' . ($n->id - 1))->assertOk()->assertJsonPath('data.0.id', $n->id);
        $this->actingAs($b, 'sanctum')->getJson('/api/notifications?after_id=' . $n->id)->assertOk()->assertJsonCount(0, 'data');

        $d = $this->actingAs($b, 'sanctum')->getJson('/api/notifications/' . $n->id)->assertOk()->json();
        $this->assertNotNull($d['read_at']);
        $this->assertSame('JEAN ENVOYEUR', $d['transaction']['sender_name']);
        $this->assertSame('MARIE RECEVEUSE', $d['transaction']['beneficiary_name']);
        $labels = array_column($d['transaction']['details'], 'label');
        $this->assertContains('Expéditeur', $labels);
        $this->assertContains('Bénéficiaire', $labels);
        $this->assertNotContains('Frais', $labels); // vue bénéficiaire
        $this->actingAs($a, 'sanctum')->getJson('/api/notifications/' . $n->id)->assertNotFound();

        // Récap de fin d'opération (statut) : détails côté expéditeur
        $st = $this->actingAs($a, 'sanctum')->getJson('/api/pay/transactions/' . $tx->id . '/status')->assertOk()->json();
        $this->assertSame('MARIE RECEVEUSE', $st['beneficiary_name']);
        $this->assertContains('Total débité', array_column($st['details'], 'label'));
    }

    public function test_admin_chooses_partner_per_country(): void
    {
        $admin = User::create(['full_name' => 'Super', 'phone' => '242069990009', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');

        $d = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/corridors')->assertOk()->json();
        $this->assertSame(['peex', 'digitwace'], array_column($d['partners'], 'key'));
        $sn = collect($d['corridors'])->firstWhere('country', 'SN');
        $this->assertSame('peex', $sn['payout_partner']);
        $this->assertSame('PEEX', $sn['payout_partner_name']);

        // Sans couverture synchronisée : WacePay refusé
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/SN', ['payout_partner' => 'digitwace'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Synchronisez'));
        \App\Models\WacepayCoverage::create(['country' => 'SN', 'payer_code' => 'SN-OM', 'payer_name' => 'Orange Money', 'payin' => false, 'payout' => true]);
        // Collecte non couverte au Sénégal : refusée
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/SN', ['collect_partner' => 'digitwace'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/SN', ['payout_partner' => 'digitwace'])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'WacePay'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/SN', ['collect_partner' => 'mtn'])->assertStatus(422);

        $flows = app(\App\Services\Peex\PeexFlowService::class);
        // WacePay choisi mais non configuré : refus explicite (pas de bascule silencieuse)
        try {
            $flows->payoutRailFor(['rail' => 'peex', 'country' => 'SN']);
            $this->fail('exception attendue');
        } catch (\App\Services\Peex\PeexException $e) {
            $this->assertStringContainsString('WacePay', $e->getMessage());
        }
        config(['flashpay.rails.digitwace.enabled' => true, 'flashpay.digitwace.enabled' => true, 'flashpay.digitwace.public_key' => 'p', 'flashpay.digitwace.private_key' => 'k']);
        $this->assertSame('digitwace', $flows->payoutRailFor(['rail' => 'peex', 'country' => 'SN']));
        $this->assertSame('peex', $flows->payoutRailFor(['rail' => 'peex', 'country' => 'CG']));
        $this->assertSame('wallet', $flows->payoutRailFor(['rail' => 'wallet', 'country' => 'CG']));
    }

    public function test_wacepay_coverage_sync_adds_corridors_and_collects(): void
    {
        config([
            'flashpay.rails.digitwace.enabled' => true, 'flashpay.digitwace.enabled' => true,
            'flashpay.digitwace.public_key' => 'p', 'flashpay.digitwace.private_key' => 'k',
            'flashpay.digitwace.base_url' => 'https://wace.test/api/',
        ]);
        $payinCalls = 0;
        Http::fake(function ($req) use (&$payinCalls) {
            $u = $req->url();
            if (str_contains($u, 'get-token')) return Http::response(['code' => 2000, 'token' => 't']);
            if (str_contains($u, 'transaction/payercode')) return Http::response(['code' => 2000, 'data' => [
                ['countryCode' => 'SEN', 'currency' => 'XOF', 'payerCode' => 'SN-OM', 'payerName' => 'Orange Money', 'type' => 'MOBILE_MONEY', 'services' => ['PAYOUT']],
                ['countryCode' => 'NGA', 'currency' => 'NGN', 'payerCode' => 'NG-OPAY', 'payerName' => 'OPay', 'type' => 'MOBILE_MONEY', 'services' => ['PAYIN', 'PAYOUT']],
                ['countryCode' => 'KEN', 'currency' => 'KES', 'payerCode' => 'KE-MPESA', 'payerName' => 'M-Pesa', 'type' => 'WALLET', 'payin' => true, 'payout' => true],
                ['countryCode' => 'NGA', 'currency' => 'NGN', 'payerCode' => 'NG-BANK', 'payerName' => 'Banques', 'type' => 'BANK', 'services' => ['PAYOUT']],
                ['countryCode' => 'XXX', 'payerCode' => 'ZZ'],
            ]]);
            if (str_ends_with($u, 'api/create')) { $payinCalls++; return Http::response(['code' => 2000, 'data' => ['transactionCode' => 'PI-1', 'status' => 'PENDING']]); }
            if (str_contains($u, 'transaction/status')) return Http::response(['code' => 2000, 'data' => ['status' => 'SUCCESS']]);
            return Http::response(['code' => 1001], 404);
        });

        $admin = User::create(['full_name' => 'Super', 'phone' => '242069990019', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $r = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors-sync/wacepay')->assertOk()->json();
        $this->assertEqualsCanonicalizing(['NG', 'KE'], $r['added']);
        $this->assertSame(3, $r['countries']);

        $d = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/corridors')->assertOk()->json();
        $ng = collect($d['corridors'])->firstWhere('country', 'NG');
        $this->assertSame('INTERNATIONAL', $ng['zone']);
        $this->assertTrue($ng['collect']);
        $this->assertTrue($ng['payout']);
        $this->assertSame('digitwace', $ng['payout_partner']);
        $this->assertSame('digitwace', $ng['collect_partner']);
        $this->assertTrue($ng['wacepay']['payin']);
        $sn = collect($d['corridors'])->firstWhere('country', 'SN');
        $this->assertSame('peex', $sn['payout_partner']); // pays PEEX existant : inchangé
        $this->assertTrue($sn['wacepay']['payout']);
        $this->assertFalse($sn['wacepay']['payin']);
        $this->assertArrayHasKey('INTERNATIONAL', $d['zones']);

        // Le numéro nigérian est reconnu et la collecte passe par WacePay
        $flows = app(\App\Services\Peex\PeexFlowService::class);
        $route = app(\App\Services\Peex\PeexCorridors::class)->resolve('+2348031234567');
        $this->assertSame('NG', $route['country']);
        $this->assertSame('digitwace', $flows->collectRailFor($route));
        $this->assertSame('digitwace', $flows->payoutRailFor($route));
        $this->assertSame('NG-OPAY', app(\App\Services\Digitwace\DigitwaceClient::class)->payerCodeFor('NG', null, 'payin'));

        $u = $this->client('242069990020', 'OLA', 0);
        $tx = $this->tx(['type' => 'cash_in', 'source_rail' => 'digitwace', 'destination_rail' => 'wallet', 'source_account' => '+2348031234567',
            'destination_wallet_id' => $u->wallet->id, 'initiated_by' => $u->id, 'status' => 'processing', 'amount' => 500,
            'meta' => ['source_country' => 'NG']]);
        $c = app(\App\Services\Digitwace\DigitwaceConnector::class)->collect('+2348031234567', 500, 'NGN', $tx->reference);
        $this->assertSame('pending', $c['status']);
        $this->assertSame(1, $payinCalls);
        $tx->update(['stage' => 'awaiting_source']);
        $req = \App\Models\DigitwaceRequest::where('operation', 'payin')->firstOrFail();
        app(\App\Services\Digitwace\DigitwaceStatusHandler::class)->refresh($req);
        $this->assertSame('successful', $tx->fresh()->status);
        $this->assertSame(500, (int) $u->wallet->fresh()->balance);
    }

    public function test_no_fee_kept_when_operation_fails(): void
    {
        $sw = app(\App\Services\SwitchService::class);

        // 1. Wallet → mobile : versement refusé → montant + frais recrédités
        $a = $this->client('242069990031', 'PAUL', 1000);
        $tx = $sw->createPending([
            'type' => 'p2p', 'source_rail' => 'wallet', 'source_wallet_id' => $a->wallet->id,
            'destination_rail' => 'peex', 'destination_account' => '+242057563644',
            'amount' => 500, 'fee' => 10, 'currency' => 'XAF', 'initiated_by' => $a->id,
        ], 'awaiting_destination');
        $this->app->make(\App\Services\WalletService::class)->debit($a->wallet->fresh(), 510);
        $led = app(\App\Services\LedgerService::class);
        $led->recordDoubleEntry($tx, "wallet:{$a->wallet->id}", 'flashpay:suspense', 500);
        $led->recordDoubleEntry($tx, "wallet:{$a->wallet->id}", 'flashpay:fees', 10);
        $sw->onDestinationFailed($tx->fresh(), 'numéro inactif');
        $this->assertSame('reversed', $tx->fresh()->status);
        $this->assertSame(1000, (int) $a->wallet->fresh()->balance);

        // 2. Carte → mobile : versement refusé → montant + frais recrédités sur le wallet
        $b = $this->client('242069990032', 'LUC', 0);
        $tx2 = $sw->createPending([
            'type' => 'p2p', 'source_rail' => 'card', 'destination_rail' => 'peex', 'destination_account' => '+242057563644',
            'amount' => 2000, 'fee' => 40, 'currency' => 'XAF', 'initiated_by' => $b->id,
        ], 'awaiting_destination');
        $led->recordDoubleEntry($tx2, 'card:client', 'flashpay:suspense', 2000);
        $led->recordDoubleEntry($tx2, 'card:client', 'flashpay:fees', 40);
        $sw->onDestinationFailed($tx2->fresh(), 'refusé');
        $this->assertSame('reversed', $tx2->fresh()->status);
        $this->assertSame(2040, (int) $b->wallet->fresh()->balance);
        $this->assertStringContainsString('frais remboursés', $tx2->fresh()->failure_reason);

        // 3. Collecte refusée : rien n'est prélevé, l'app affiche 0 de frais
        $tx3 = $sw->createPending([
            'type' => 'cash_in', 'source_rail' => 'peex', 'source_account' => '+242067601919', 'destination_rail' => 'wallet',
            'destination_wallet_id' => $b->wallet->id, 'amount' => 100, 'fee' => 1, 'currency' => 'XAF', 'initiated_by' => $b->id,
        ]);
        $sw->onSourceFailed($tx3->fresh(), 'annulé');
        $st = $this->actingAs($b, 'sanctum')->getJson('/api/pay/transactions/' . $tx3->id . '/status')->assertOk()->json();
        $this->assertSame(0, $st['fee']);
        $rows = collect($st['details'])->pluck('value', 'label');
        $this->assertStringContainsString('non prélevés', $rows['Frais']);
        $this->assertSame('0 XAF', $rows['Total débité']);

        // Aucun frais comptabilisé en produit pour ces opérations
        $fees = \App\Models\LedgerEntry::whereIn('transaction_id', [$tx->id, $tx2->id, $tx3->id])->get();
        $net = $fees->where('account', 'flashpay:fees')->sum(fn ($e) => $e->type === 'credit' ? $e->amount : -$e->amount);
        $this->assertSame(0, (int) $net);
    }

    public function test_wacepay_balances(): void
    {
        $svc = app(\App\Services\Digitwace\WacepayBalanceService::class);
        $this->assertSame([['label' => 'Compte XAF', 'currency' => 'XAF', 'balance' => 150000.0]], $svc->parse(['code' => 2000, 'data' => ['balance' => 150000, 'currency' => 'XAF']]));
        $this->assertCount(2, $svc->parse(['data' => [['currency' => 'XAF', 'balance' => 10], ['currency' => 'XOF', 'availableBalance' => 20]]]));
        $this->assertCount(2, $svc->parse(['data' => ['XAF' => 5, 'EUR' => 1]]));

        config(['flashpay.digitwace.enabled' => true, 'flashpay.digitwace.public_key' => 'p', 'flashpay.digitwace.private_key' => 'k',
            'flashpay.digitwace.base_url' => 'https://wace.test/api/', 'flashpay.digitwace.low_balance_alert' => 100000]);
        Http::fake(fn ($r) => str_contains($r->url(), 'get-token')
            ? Http::response(['code' => 2000, 'token' => 't'])
            : Http::response(['code' => 2000, 'data' => [['currency' => 'XAF', 'balance' => 80000, 'name' => 'Compte principal']]]));
        $admin = User::create(['full_name' => 'Super', 'phone' => '242069990041', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $b = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/digitwace/balances?refresh=1')->assertOk()->json();
        $this->assertTrue($b['ok']);
        $this->assertSame('Compte principal', $b['accounts'][0]['label']);
        $this->assertTrue($b['accounts'][0]['low']);
        $this->assertEquals(80000, $svc->availableFor('XAF'));
    }
}

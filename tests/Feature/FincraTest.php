<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Fincra : collecte / versement / remboursement mobile money (national), remittance internationale, webhook signé. */
class FincraTest extends TestCase
{
    protected array $sent = [];
    protected array $charge = [];   // notre référence de collecte → statut Fincra
    protected array $payout = [];   // customerReference → statut Fincra
    protected array $reject = [];   // chemins qui répondent 422
    protected int $seq = 500;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.fincra.enabled' => true, 'flashpay.fincra.secret_key' => 'sk_test_fincra', 'flashpay.fincra.public_key' => 'pk_test_fincra',
            'flashpay.fincra.business_id' => '64f000000000000000000001', 'flashpay.fincra.base_url' => 'https://sandboxapi.fincra.test',
            'flashpay.fincra.webhook_secret' => 'whsec', 'flashpay.rails.fincra.enabled' => true, 'flashpay.rails.fincra_remit.enabled' => true,
            'flashpay.failover.enabled' => false, 'flashpay.remit.min_kyc_tier' => 0,
        ]);
        Http::fake(function ($req) {
            $this->sent[] = $req;
            $path = parse_url($req->url(), PHP_URL_PATH);
            foreach ($this->reject as $suffix) {
                if (str_ends_with($path, $suffix)) {
                    return Http::response(['success' => false, 'error' => 'Invalid beneficiary', 'errorType' => 'VALIDATION_ERROR'], 422);
                }
            }
            $q = [];
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            return match (true) {
                $path === '/checkout/charges' => Http::response(['status' => true, 'message' => 'Charge initiated', 'data' => [
                    'id' => ++$this->seq, 'reference' => $req['reference'], 'status' => 'pending', 'auth_model' => null,
                    'message' => 'Composez *105# pour valider le paiement de ' . $req['amount'] . ' XAF.']]),
                str_starts_with($path, '/checkout/charges/verify/') => isset($this->charge[$ref = rawurldecode(basename($path))])
                    ? Http::response(['status' => true, 'data' => ['reference' => $ref, 'status' => $this->charge[$ref], 'amountReceived' => 1000]])
                    : Http::response(['status' => true, 'data' => ['reference' => $ref, 'status' => 'pending']]),
                $path === '/disbursements/payouts' => Http::response(['success' => true, 'message' => 'Payout initiated successfully.', 'data' => [
                    'id' => ++$this->seq, 'reference' => 'fx' . $this->seq, 'customerReference' => $req['customerReference'], 'status' => 'processing', 'fee' => 150]]),
                str_starts_with($path, '/disbursements/payouts/customer-reference/') => Http::response(['success' => true, 'data' => [
                    'customerReference' => $ref = rawurldecode(basename($path)), 'status' => $this->payout[$ref] ?? 'processing',
                    'reason' => ($this->payout[$ref] ?? null) === 'failed' ? 'Incorrect beneficiary details' : 'Payout processing']]),
                $path === '/core/banks' => Http::response(['success' => true, 'data' => ($q['paymentDestination'] ?? '') === 'bank_account'
                    ? [['id' => 44, 'code' => 'FR763000', 'name' => 'BNP Paribas']]
                    : [['id' => 1, 'code' => 'MTN', 'name' => 'MTN Mobile Money', 'isMobileVerified' => true], ['id' => 2, 'code' => 'AIRTEL', 'name' => 'Airtel Money', 'isMobileVerified' => true]]]),
                $path === '/wallets' => Http::response(['success' => true, 'data' => [['currency' => 'XAF', 'availableBalance' => 9000000, 'ledgerBalance' => 9000000], ['currency' => 'USD', 'availableBalance' => 5000]]]),
                $path === '/quotes/generate' => Http::response(['success' => true, 'data' => ['reference' => 'QT-' . $req['destinationCurrency'], 'sourceCurrency' => $req['sourceCurrency'],
                    'destinationCurrency' => $req['destinationCurrency'], 'sourceAmount' => 112.5, 'destinationAmount' => (float) $req['amount'], 'rate' => 0.92, 'fee' => 1.5]]),
                default => Http::response(['message' => 'not found'], 404),
            };
        });
    }

    protected function user(string $phone, int $balance = 0): User
    {
        $u = User::create(['full_name' => 'JEAN TEST', 'phone' => $phone, 'password' => bcrypt('x'), 'status' => 'active', 'kyc_tier' => 2]);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        return $u->fresh('wallet');
    }

    protected function admin(): User
    {
        $a = User::create(['full_name' => 'ADMIN', 'phone' => '242060000099', 'password' => bcrypt('x'), 'status' => 'active']);
        $a->assignRole('super_admin');
        return $a;
    }

    protected function signed(array $payload): array
    {
        return ['signature' => hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'whsec')];
    }

    public function test_mobile_transfer_collect_payout_failure_and_refund_through_fincra(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CG', ['partner' => 'fincra'])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Fincra'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/FR', ['partner' => 'fincra'])->assertStatus(422);

        $u = $this->user('242061112233');
        $tx = app(\App\Services\Peex\PeexFlowService::class)->mobileTransfer($u, '+242061112233', '+242051112233', 1000, ['beneficiary_name' => 'BENEF TEST']);
        $this->assertSame(['fincra', 'fincra', 'awaiting_source'], [$tx->source_rail, $tx->destination_rail, $tx->stage]);
        $charge = collect($this->sent)->first(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/checkout/charges'));
        $this->assertSame(['sk_test_fincra', '64f000000000000000000001', 'pk_test_fincra'], [$charge->header('api-key')[0], $charge->header('x-business-id')[0], $charge->header('x-pub-key')[0]]);
        $this->assertSame(['mobile_money', 'MTN', 'XAF', '+242061112233', $tx->reference . '-C'], [$charge['type'], $charge['operator'], $charge['currency'], $charge['phone'], $charge['reference']]);
        $this->assertSame(url('/api/webhooks/fincra'), $charge['webhookUrl']);

        // L'app affiche les instructions de l'opérateur
        $this->actingAs($u, 'sanctum')->getJson('/api/pay/transactions/' . $tx->id . '/status')->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, '*105#'))->assertJsonPath('otp_required', false);

        // Webhook non signé : ignoré ; webhook signé « successful » non cru tant que verify répond pending
        $event = ['event' => 'charge.successful', 'data' => ['reference' => $tx->reference . '-C', 'status' => 'success']];
        $this->postJson('/api/webhooks/fincra', $event, ['signature' => 'bad'])->assertOk();
        $this->postJson('/api/webhooks/fincra', $event, $this->signed($event))->assertOk();
        $this->assertSame('awaiting_source', $tx->fresh()->stage);

        // Collecte confirmée → versement Airtel lancé (mobileMoneyCode lu sur /core/banks)
        $this->charge[$tx->reference . '-C'] = 'success';
        Cache::flush();
        $this->postJson('/api/webhooks/fincra', $event, $this->signed($event))->assertOk();
        $this->assertSame('awaiting_destination', $tx->fresh()->stage);
        $payout = collect($this->sent)->first(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/disbursements/payouts'));
        $this->assertSame(['mobile_money_wallet', 'XAF', 'XAF', 1000, $tx->reference], [$payout['paymentDestination'], $payout['sourceCurrency'], $payout['destinationCurrency'], $payout['amount'], $payout['customerReference']]);
        $this->assertSame(['AIRTEL', '242051112233', 'CG', 'BENEF', 'TEST', 'individual'], [$payout['beneficiary']['mobileMoneyCode'], $payout['beneficiary']['accountNumber'],
            $payout['beneficiary']['country'], $payout['beneficiary']['firstName'], $payout['beneficiary']['lastName'], $payout['beneficiary']['type']]);
        $this->assertSame('64f000000000000000000001', $payout['business']);

        // Versement refusé (relu chez Fincra) → remboursement Fincra vers le numéro MTN débité
        $this->payout[$tx->reference] = 'failed';
        Artisan::call('remit:sync');
        $this->assertSame(['processing', 'awaiting_refund'], [$tx->fresh()->status, $tx->fresh()->stage]);
        $refund = RemitRequest::where('partner', 'fincra')->where('method', 'refund')->firstOrFail();
        $this->assertSame($tx->reference . '-R', $refund->reference);
        $call = collect($this->sent)->filter(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/disbursements/payouts'))->last();
        $this->assertSame(['MTN', '242061112233', $tx->amount + $tx->fee], [$call['beneficiary']['mobileMoneyCode'], $call['beneficiary']['accountNumber'], $call['amount']]);

        $this->payout[$refund->reference] = 'successful';
        Artisan::call('remit:sync');
        $this->assertSame('reversed', $tx->fresh()->status);

        // Console : passerelle, frais par opération, page Fincra
        $g = \App\Support\TransactionPresenter::gateway($tx->fresh());
        $this->assertSame(['Fincra', 'Fincra'], [$g['in']['partner'], $g['out']['partner']]);
        $legs = collect(\App\Support\PartnerFees::of($tx->fresh())['legs']);
        $this->assertSame(['collect', 'payout', 'refund'], $legs->pluck('kind')->all());
        $o = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/fincra/overview')->assertOk();
        $this->assertTrue($o->json('configured'));
        $this->assertSame(['CG'], $o->json('countries_collect'));
        $this->assertCount(3, $o->json('requests'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/fincra/test')->assertOk()->assertJsonPath('ok', true);
        $this->assertSame('MTN', $this->actingAs($admin, 'sanctum')->getJson('/api/admin/fincra/institutions/CG')->json('mobile_money_wallet.0.code'));
    }

    public function test_rejected_payout_is_definitive_and_unknown_collect_fails_after_delay(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/corridors/CG', ['partner' => 'fincra'])->assertOk();
        $u = $this->user('242061112255');
        $tx = app(\App\Services\Peex\PeexFlowService::class)->mobileTransfer($u, '+242061112255', '+242051112255', 2000, ['beneficiary_name' => 'BENEF TEST']);

        // Fincra refuse le versement (422) : échec certain ; le remboursement Fincra vers le numéro
        // débité est refusé lui aussi → montant + frais recrédités sur le wallet FlashPay
        $this->reject = ['/disbursements/payouts'];
        $this->charge[$tx->reference . '-C'] = 'successful';
        Artisan::call('remit:sync');
        $this->assertSame('failed', RemitRequest::where('partner', 'fincra')->where('reference', $tx->reference)->value('status'));
        $this->assertSame('failed', RemitRequest::where('partner', 'fincra')->where('reference', $tx->reference . '-R')->value('status'));
        $this->assertSame('reversed', $tx->fresh()->status);
        $this->assertSame($tx->amount + $tx->fee, (int) $u->wallet->fresh()->balance);

        // Collecte jamais vue chez Fincra (404 sur verify) : échec après le délai, rien débité
        $this->reject = ['/checkout/charges'];
        $tx2 = app(\App\Services\Peex\PeexFlowService::class)->mobileTransfer($u, '+242061112255', '+242051112255', 500, ['beneficiary_name' => 'BENEF TEST']);
        $this->assertSame('failed', $tx2->status);
    }

    public function test_international_wallet_and_bank_transfers_through_fincra(): void
    {
        ExchangeRate::updateOrCreate(['base' => 'XAF', 'quote' => 'EUR'], ['rate' => 0.001524, 'margin_percent' => 0, 'active' => true, 'source' => 'test']);
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $o = $this->getJson('/api/admin/remit/overview')->assertOk();
        $this->assertContains('fincra_remit', collect($o->json('partners'))->pluck('key')->all());
        $this->assertContains('fincra_remit', collect($o->json('countries'))->firstWhere('iso', 'CM')['available']);
        $this->postJson('/api/admin/remit/countries/CM', ['partner' => 'fincra_remit'])->assertOk();
        $this->postJson('/api/admin/remit/countries/FR', ['partner' => 'fincra_remit'])->assertOk();
        $this->assertEquals([['currency' => 'XAF', 'balance' => 9000000], ['currency' => 'USD', 'balance' => 5000]], $this->getJson('/api/admin/remit/balances')->json('fincra_remit.accounts'));

        $c = $this->user('242069887766', 500000);
        $this->actingAs($c, 'sanctum')->putJson('/api/pay/international/profile', [
            'first_name' => 'Basile', 'last_name' => 'Test', 'gender' => 'M', 'date_of_birth' => '1990-01-01', 'place_of_birth' => 'Brazzaville',
            'nationality' => 'CG', 'occupation' => 'Informaticien', 'email' => 'basile@test.cg', 'civility' => 'Married', 'pep' => false,
            'id_type' => 'PP', 'id_number' => 'X123', 'id_issuer_country' => 'CG', 'id_issue_date' => '2020-01-01', 'id_expiry' => now()->addYear()->toDateString(),
            'country' => 'CG', 'city' => 'Brazzaville', 'district' => 'Centre-ville', 'street' => 'Avenue de la Paix',
        ])->assertOk();
        foreach (['passport', 'selfie'] as $t) {
            \App\Models\KycDocument::create(['user_id' => $c->id, 'type' => $t, 'path' => "kyc/{$t}.jpg", 'status' => 'pending']);
        }
        $sender = ['first_name' => 'Basile', 'last_name' => 'Test', 'id_type' => 'PP', 'id_number' => 'X123', 'id_expiry' => now()->addYear()->toDateString(),
            'date_of_birth' => '1990-01-01', 'gender' => 'M', 'nationality' => 'CG', 'address' => 'Centre-ville', 'city' => 'Brazzaville'];

        // Cameroun, mobile money : opérateurs Fincra proposés dans l'app
        $svc = $this->getJson('/api/pay/international/services?country=CM')->assertOk();
        $this->assertSame('MTN', $svc->json('methods.wallet.0.code'));
        $r = $this->postJson('/api/pay/international/send', [
            'amount' => 50000, 'country' => 'CM', 'method' => 'wallet', 'business_type' => 'p2p', 'service' => ['code' => 'MTN', 'name' => 'MTN Mobile Money'],
            'sender' => $sender, 'beneficiary' => ['first_name' => 'Paul', 'last_name' => 'Nkodo'], 'account' => ['msisdn' => '670000000'],
            'why' => ['reason' => 'Family Maintainance', 'origin_fund' => 'Salary', 'relation' => 'Brother'],
        ])->assertStatus(202);
        $tx = Transaction::findOrFail($r->json('id'));
        $this->assertSame('fincra_remit', $tx->destination_rail);
        $send = collect($this->sent)->first(fn ($q) => str_ends_with(parse_url($q->url(), PHP_URL_PATH), '/disbursements/payouts'));
        $this->assertSame(['XAF', 'XAF', 50000, 'mobile_money_wallet', $tx->reference], [$send['sourceCurrency'], $send['destinationCurrency'], $send['amount'], $send['paymentDestination'], $send['customerReference']]);
        $this->assertSame(['MTN', '237670000000', 'CM', 'Paul', 'Nkodo'], [$send['beneficiary']['mobileMoneyCode'], $send['beneficiary']['accountNumber'], $send['beneficiary']['country'],
            $send['beneficiary']['firstName'], $send['beneficiary']['lastName']]);
        $this->assertArrayNotHasKey('quoteReference', $send->data());

        Artisan::call('remit:sync');
        $this->assertSame('processing', $tx->fresh()->status);
        $this->payout[$tx->reference] = 'failed';
        Artisan::call('remit:sync');
        $this->assertSame('reversed', $tx->fresh()->status);
        $this->assertSame(500000, (int) $c->wallet->fresh()->balance);

        // France, virement bancaire en EUR depuis le solde USD Fincra (devis + quoteReference + sepa)
        config(['flashpay.fincra.remit_source_currency' => 'USD']);
        $banks = $this->postJson('/api/pay/international/banks', ['country' => 'FR', 'service' => ['code' => '', 'name' => 'Virement bancaire']])->assertOk();
        $this->assertSame(44, $banks->json('banks.0.id'));
        $r = $this->postJson('/api/pay/international/send', [
            'amount' => 65600, 'country' => 'FR', 'method' => 'bank', 'business_type' => 'p2p',
            'sender' => $sender, 'beneficiary' => ['first_name' => 'Jean', 'last_name' => 'Martin', 'phone' => '+33612345678'],
            'account' => ['account' => 'FR76 3000 6000 0112 3456 7890 189', 'bank_id' => 44, 'bank_name' => 'BNP Paribas'],
            'why' => ['reason' => 'Family Maintainance', 'origin_fund' => 'Salary', 'relation' => 'Brother'],
        ])->assertStatus(202);
        $tx = Transaction::findOrFail($r->json('id'));
        $quote = collect($this->sent)->first(fn ($q) => str_ends_with(parse_url($q->url(), PHP_URL_PATH), '/quotes/generate'));
        $this->assertSame(['USD', 'EUR', 'receive', 'disbursement', 'bank_account', 'sepa'], [$quote['sourceCurrency'], $quote['destinationCurrency'], $quote['action'],
            $quote['transactionType'], $quote['paymentDestination'], $quote['paymentScheme']]);
        $send = collect($this->sent)->filter(fn ($q) => str_ends_with(parse_url($q->url(), PHP_URL_PATH), '/disbursements/payouts'))->last();
        $this->assertSame(['USD', 'EUR', 112.5, 'QT-EUR', 'bank_account', 'FR763000', 'FR7630006000011234567890189'], [$send['sourceCurrency'], $send['destinationCurrency'], $send['amount'],
            $send['quoteReference'], $send['paymentDestination'], $send['beneficiary']['bankCode'], $send['beneficiary']['accountNumber']]);
        $this->payout[$tx->reference] = 'successful';
        $event = ['event' => 'payout.successful', 'data' => ['customerReference' => $tx->reference, 'status' => 'successful']];
        $this->postJson('/api/webhooks/fincra', $event, $this->signed($event))->assertOk();
        $this->assertSame('successful', $tx->fresh()->status);
    }
}

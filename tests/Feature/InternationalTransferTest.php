<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\RemitParty;
use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Transferts internationaux P2P / P2B / B2P / B2B : WacePay API v2 et TerraPay. */
class InternationalTransferTest extends TestCase
{
    protected array $sent = [];
    protected string $waceStatus = 'WAITING CONFIRMATION';
    protected string $terraStatus = '3050:Remit Acknowledged.';
    protected string $terraAccount = 'available';
    protected string $pawaStatus = 'PROCESSING';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.rails.wacepay_remit.enabled' => true, 'flashpay.wacepay_remit.enabled' => true,
            'flashpay.wacepay_remit.base_url' => 'https://sandbox.wace.test/api/v1', 'flashpay.wacepay_remit.email' => 'p@wace.test', 'flashpay.wacepay_remit.password' => 'pwd',
            'flashpay.rails.terrapay.enabled' => true, 'flashpay.terrapay.enabled' => true,
            'flashpay.terrapay.base_url' => 'https://uat.terra.test:21211', 'flashpay.terrapay.username' => 'fp', 'flashpay.terrapay.password' => 'secret',
            'flashpay.rails.pawapay.enabled' => true, 'flashpay.pawapay.enabled' => true,
            'flashpay.pawapay.base_url' => 'https://api.sandbox.pawa.test', 'flashpay.pawapay.token' => 'PTOK',
            'flashpay.remit.partners' => ['FR' => 'terrapay', '*' => 'wacepay_remit'],
            'flashpay.remit.min_kyc_tier' => 0,
        ]);
        ExchangeRate::updateOrCreate(['base' => 'XAF', 'quote' => 'EUR'], ['rate' => 0.001524, 'margin_percent' => 0, 'active' => true, 'source' => 'test']);

        Http::fake(function ($req) {
            $u = $req->url();
            $this->sent[] = $req;
            return match (true) {
                // ---- WacePay API v2
                str_ends_with($u, '/login') => $req['email'] === 'p@wace.test' && $req['password'] === 'pwd'
                    ? Http::response(['status' => 2000, 'access_token' => 'WTOK', 'token_type' => 'bearer', 'expires_in' => 3600])
                    : Http::response(['status' => 1002, 'message' => 'bad'], 401),
                str_ends_with($u, '/account/balance') => Http::response(['status' => 2000, 'data' => ['Balance' => '9000000.000', 'Currency' => 'XAF']]),
                str_ends_with($u, '/transaction/payouts/services') => Http::response(['status' => 2000, 'data' => [
                    ['ServiceCode' => 'B', 'ServiceName' => 'BANKS DEPOSIT'], ['ServiceCode' => 'OM', 'ServiceName' => 'ORANGE MONEY'], ['ServiceCode' => 'CP', 'ServiceName' => 'CASH PICKUP'],
                ]]),
                str_ends_with($u, '/transaction/payercode') => Http::response(['status' => 2000, 'transaction' => ['PayerCode' => 'PAY-' . $req['payoutService']]]),
                str_ends_with($u, '/transaction/bank/list') => Http::response(['status' => 2000, 'data' => [['BankName' => 'SGBCI', 'BankID' => 7, 'BankCode' => 'CI008']]]),
                str_ends_with($u, '/sender/create') => Http::response(['messages' => 'ok', 'sender' => ['Code' => 'S-' . ($req['type'] ?? '?')], 'stauts' => 2000]),
                str_ends_with($u, '/beneficiary/create') => Http::response(['messages' => 'ok', 'beneficiary' => ['Code' => 'B-' . ($req['type'] ?? '?')], 'status' => 201]),
                str_ends_with($u, '/transaction/wallet/create') => Http::response(['message' => 'ok', 'transaction' => ['reference' => 'WPPX1', 'feeds' => 350], 'status' => 201]),
                str_ends_with($u, '/transaction/bank/create') => Http::response(['message' => 'ok', 'transaction' => ['reference' => 'WPPX2', 'feeds' => 7.5], 'status' => 201]),
                str_contains($u, '/transaction/status/') => Http::response(['status' => 204, 'transaction' => ['Status' => $this->waceStatus]]),
                str_ends_with($u, '/transaction/confirm') => tap(Http::response(['status' => 2000, 'messages' => 'confirmed']), fn () => $this->waceStatus = 'PROCESSING'),
                str_contains($u, '/transaction/relation') => Http::response(['Brother' => 'Brother', 'Supplier' => 'Supplier']),
                str_contains($u, '/transaction/origin_fund/') => Http::response(['Salary' => 'Salary']),
                str_contains($u, '/transaction/reason/') => Http::response(['Family Maintainance' => 'Family Maintainance']),
                // ---- TerraPay
                str_contains($u, '/eig/gsma/accounts/') => Http::response(['status' => $this->terraAccount, 'subStatus' => $this->terraAccount === 'available' ? '6000:Validation Success' : '6001:Not found', 'lei' => '']),
                str_ends_with($u, '/eig/gsma/quotations') => Http::response(['quotes' => [['quoteId' => 'QT1', 'sendingAmount' => '65616.80', 'sendingCurrency' => 'XAF',
                    'receivingAmount' => (string) $req['requestAmount'], 'receivingCurrency' => 'EUR', 'fxRate' => '0.001524']], 'quotationReference' => 'QT1', 'quotationStatus' => '2000:Quote Success']),
                str_ends_with($u, '/eig/gsma/transactions') => Http::response(['transactionStatus' => '3050:Remit Acknowledged.', 'transactionReference' => 'TPKM1']),
                str_contains($u, '/eig/gsma/transactions/TPKM1') => Http::response(['transactionStatus' => $this->terraStatus, 'transactionReference' => 'TPKM1']),
                // ---- pawaPay
                str_ends_with($u, '/v2/predict-provider') => Http::response(['country' => 'CMR', 'provider' => 'MTN_MOMO_CMR', 'phoneNumber' => '237670000000']),
                str_contains($u, '/v2/active-conf') => Http::response(['countries' => [['country' => 'CMR', 'providers' => [
                    ['provider' => 'MTN_MOMO_CMR', 'currencies' => [['currency' => 'XAF', 'operationTypes' => ['REMITTANCE' => ['status' => 'OPERATIONAL']]]]],
                    ['provider' => 'ORANGE_CMR', 'currencies' => [['currency' => 'XAF', 'operationTypes' => ['REMITTANCE' => ['status' => 'OPERATIONAL']]]]],
                ]]]]),
                str_ends_with($u, '/v2/remittances') => Http::response(['remittanceId' => $req['remittanceId'], 'status' => 'ACCEPTED', 'created' => now()->toIso8601String()]),
                str_contains($u, '/v2/remittances/') => Http::response(['status' => 'FOUND', 'data' => ['status' => $this->pawaStatus]]),
                str_contains($u, '/v2/wallet-balances') => Http::response(['balances' => [['country' => 'CMR', 'balance' => '500000', 'currency' => 'XAF']]]),
                default => Http::response(['message' => 'not found ' . $u], 404),
            };
        });
    }

    protected function client(int $balance = 500000): User
    {
        $u = User::create(['full_name' => 'Basile Test Expediteur', 'phone' => '242069887766', 'password' => 'secret123', 'kyc_tier' => 2]);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        $this->activate($u);
        return $u->fresh();
    }

    /** « Activer l'international » : profil + pièces (passeport, selfie). */
    protected function activate(User $u): void
    {
        $this->actingAs($u, 'sanctum')->putJson('/api/pay/international/profile', [
            'first_name' => 'Basile', 'last_name' => 'Test', 'gender' => 'M', 'date_of_birth' => '1990-01-01', 'place_of_birth' => 'Brazzaville',
            'nationality' => 'CG', 'occupation' => 'Informaticien', 'email' => 'basile@test.cg', 'civility' => 'Married', 'pep' => false,
            'id_type' => 'PP', 'id_number' => 'X123', 'id_issuer_country' => 'CG', 'id_issue_date' => '2020-01-01', 'id_expiry' => now()->addYear()->toDateString(),
            'country' => 'CG', 'city' => 'Brazzaville', 'district' => 'Centre-ville', 'street' => 'Avenue de la Paix',
        ])->assertOk();
        foreach (['passport', 'selfie'] as $t) {
            \App\Models\KycDocument::create(['user_id' => $u->id, 'type' => $t, 'path' => "kyc/{$t}.jpg", 'status' => 'pending']);
        }
    }

    protected function sender(array $extra = []): array
    {
        return $extra + ['first_name' => 'Basile', 'last_name' => 'Test', 'id_type' => 'PP', 'id_number' => 'X123', 'id_expiry' => now()->addYear()->toDateString(),
            'date_of_birth' => '1990-01-01', 'gender' => 'M', 'nationality' => 'CG', 'address' => 'Centre-ville', 'city' => 'Brazzaville'];
    }

    public function test_options_quote_and_p2p_wallet_via_wacepay_then_paid(): void
    {
        $c = $this->client();
        $this->actingAs($c, 'sanctum');

        $o = $this->getJson('/api/pay/international/options')->assertOk();
        $this->assertTrue($o->json('available'));
        $this->assertSame(['p2p', 'p2b', 'b2p', 'b2b'], collect($o->json('business_types'))->pluck('key')->all());
        $ci = collect($o->json('countries'))->firstWhere('iso', 'CI');
        $this->assertSame(['wallet', 'bank', 'cash'], $ci['methods']);
        $this->assertSame('terrapay', collect($o->json('countries'))->firstWhere('iso', 'FR')['partner']);

        $svc = $this->getJson('/api/pay/international/services?country=CI')->assertOk();
        $this->assertSame('ORANGE MONEY', $svc->json('methods.wallet.0.name'));
        $this->assertSame('B', $svc->json('methods.bank.0.code'));

        $q = $this->postJson('/api/pay/international/quote', ['amount' => 100000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p'])->assertOk();
        $this->assertTrue($q->json('available'), implode(' ', (array) $q->json('problems')));
        $this->assertSame(100000, $q->json('receive_amount')); // XAF → XOF parité fixe

        $r = $this->postJson('/api/pay/international/send', [
            'amount' => 100000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p',
            'service' => ['code' => 'OM', 'name' => 'ORANGE MONEY'],
            'sender' => $this->sender(),
            'beneficiary' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'city' => 'Abidjan'],
            'account' => ['msisdn' => '0707070707'],
            'why' => ['reason' => 'Family Maintainance', 'origin_fund' => 'Salary', 'relation' => 'Brother'],
        ])->assertStatus(202);
        $tx = Transaction::findOrFail($r->json('id'));
        $this->assertSame('international_transfer', $tx->type);
        $this->assertSame('wacepay_remit', $tx->destination_rail);
        $this->assertSame('awaiting_destination', $tx->stage);
        $this->assertSame(500000 - 100000 - (int) $tx->fee, (int) $c->wallet->fresh()->balance);

        $create = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/transaction/wallet/create'));
        $this->assertSame('Bearer WTOK', $create->header('Authorization')[0]);
        $this->assertSame('2250707070707', $create['mobileReceiveNumber']);
        $this->assertSame('100000', $create['amountToPaid']);
        $this->assertSame('XAF', $create['fromCurrency']);
        $this->assertSame('PAY-OM', $create['payerCode']);
        $this->assertSame('S-P', $create['senderCode']);
        $this->assertSame('B-P', $create['beneficiaryCode']);
        // Expéditeur / bénéficiaire créés une seule fois (pré-contrôle puis envoi)
        $this->assertSame(1, collect($this->sent)->filter(fn ($q) => str_ends_with($q->url(), '/sender/create'))->count());
        $this->assertSame(2, RemitParty::count());

        // Suivi : WAITING CONFIRMATION → confirmation auto → PROCESSING, puis PAID
        Artisan::call('remit:sync');
        $this->assertTrue(collect($this->sent)->contains(fn ($q) => str_ends_with($q->url(), '/transaction/confirm') && $q['reference'] === 'WPPX1'));
        $this->assertSame('processing', $tx->fresh()->status);
        $this->waceStatus = 'PAID';
        Artisan::call('remit:sync');
        $this->assertSame('successful', $tx->fresh()->status);
        $this->assertNotNull(RemitRequest::first()->finalized_at);
    }

    public function test_b2b_bank_via_wacepay_cancelled_refunds_wallet_with_fees(): void
    {
        $c = $this->client();
        $this->actingAs($c, 'sanctum');

        $banks = $this->postJson('/api/pay/international/banks', ['country' => 'CI', 'service' => ['code' => 'B', 'name' => 'BANKS DEPOSIT']])->assertOk();
        $this->assertSame(7, $banks->json('banks.0.id'));

        // Entreprise : RCCM obligatoire
        $this->postJson('/api/pay/international/send', [
            'amount' => 200000, 'country' => 'CI', 'method' => 'bank', 'business_type' => 'b2b', 'service' => ['code' => 'B', 'name' => 'BANKS DEPOSIT'],
            'sender' => $this->sender(['business_name' => 'FlashPay Test SARL', 'registered_at' => '2020-01-01']),
            'beneficiary' => ['business_name' => 'Fournisseur CI SA', 'registration_number' => 'CI-ABJ-01'],
            'account' => ['account' => 'CI0080 1234 5678', 'bank_id' => 7, 'bank_name' => 'SGBCI'],
        ])->assertStatus(422)->assertJsonFragment(['message' => "Indiquez le numéro RCCM (registre du commerce) de l'entreprise (expéditeur)."]);
        $this->assertSame(500000, (int) $c->wallet->fresh()->balance);

        $r = $this->postJson('/api/pay/international/send', [
            'amount' => 200000, 'country' => 'CI', 'method' => 'bank', 'business_type' => 'b2b', 'service' => ['code' => 'B', 'name' => 'BANKS DEPOSIT'],
            'sender' => $this->sender(['business_name' => 'FlashPay Test SARL', 'registration_number' => 'CG-BZV-2020-B-1', 'registered_at' => '2020-01-01']),
            'beneficiary' => ['business_name' => 'Fournisseur CI SA', 'business_type' => 'SA', 'registration_number' => 'CI-ABJ-01', 'phone' => '+2250102030405'],
            'account' => ['account' => 'CI0080 1234 5678', 'bank_id' => 7, 'bank_name' => 'SGBCI', 'swift' => 'sgcicia'],
            'why' => ['reason' => 'Advanced Goods Payments', 'origin_fund' => 'Business Income', 'relation' => 'Supplier'],
        ])->assertStatus(202);
        $tx = Transaction::findOrFail($r->json('id'));

        $sender = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/sender/create'));
        $this->assertSame('B', $sender['type']);
        $this->assertSame('RCCM', $sender['idType']);
        $this->assertSame('CG-BZV-2020-B-1', $sender['idNumber']);
        $this->assertSame('FlashPay Test SARL', $sender['businessName']);
        // Situation familiale et PPE déclarées par le client (orthographe WacePay « Maried »)
        $this->assertSame('Maried', $sender['civility']);
        $this->assertSame(0, $sender['pep']);
        $bank = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/transaction/bank/create'));
        $this->assertSame('CI008012345678', $bank['bankAccount']);
        $this->assertSame(7, $bank['bankId']);
        $this->assertSame('SGCICIA', $bank['bankSwCode']);
        $this->assertSame('S-B', $bank['senderCode']);
        $this->assertSame('B-B', $bank['beneficiaryCode']);

        $this->waceStatus = 'CANCEL';
        Artisan::call('remit:sync');
        $tx->refresh();
        $this->assertSame('reversed', $tx->status);
        $this->assertSame(500000, (int) $c->wallet->fresh()->balance); // montant + frais remboursés
    }

    public function test_terrapay_wallet_checks_account_then_sends_with_reverse_quote(): void
    {
        $c = $this->client();
        $this->actingAs($c, 'sanctum');

        // Compte indisponible : refus avant tout débit
        $this->terraAccount = 'unregistered';
        $payload = [
            'amount' => 65600, 'country' => 'FR', 'method' => 'wallet', 'business_type' => 'b2p',
            'sender' => $this->sender(['business_name' => 'FlashPay Test SARL', 'registration_number' => 'CG-BZV-1', 'registered_at' => '2020-01-01']),
            'beneficiary' => ['first_name' => 'Jean', 'last_name' => 'Martin'],
            'account' => ['msisdn' => '+33612345678'],
        ];
        $this->postJson('/api/pay/international/send', $payload)->assertStatus(422);
        $this->assertSame(500000, (int) $c->wallet->fresh()->balance);
        $this->assertSame(0, Transaction::where('type', 'international_transfer')->count());

        $this->terraAccount = 'available';
        $r = $this->postJson('/api/pay/international/send', $payload)->assertStatus(202);
        $tx = Transaction::findOrFail($r->json('id'));
        $this->assertSame('terrapay', $tx->destination_rail);
        $this->assertSame('EUR', $tx->destination_currency);

        $acc = collect($this->sent)->first(fn ($q) => str_contains($q->url(), '/eig/gsma/accounts/msisdn/'));
        $this->assertSame('fp', $acc->header('X-USERNAME')[0]);
        $this->assertSame(hash('sha256', 'secret'), $acc->header('X-PASSWORD')[0]);
        $this->assertSame('CG', $acc->header('X-ORIGINCOUNTRY')[0]);
        $quote = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/eig/gsma/quotations'));
        $this->assertSame('EUR', $quote['requestCurrency']); // devis inverse : montant reçu garanti
        $this->assertSame(number_format((float) $tx->destination_amount, 2, '.', ''), $quote['requestAmount']);
        $send = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/eig/gsma/transactions'));
        $this->assertSame('QT1', $send['internationalTransferInformation']['quoteId']);
        $this->assertSame('FR', $send['internationalTransferInformation']['receivingCountry']);
        $this->assertSame($tx->reference, $send['requestingOrganisationTransactionReference']);
        $this->assertSame('FlashPay Test SARL', $send['senderKyc']['subjectName']['fullName']);
        $this->assertSame('businessregistration', $send['senderKyc']['idDocument'][0]['idType']);
        $this->assertSame('Jean Martin', $send['recipientKyc']['subjectName']['fullName']);
        $this->assertSame('+33612345678', $send['creditParty'][0]['value']);

        $this->terraStatus = '3000:Remit Success';
        Artisan::call('remit:sync');
        $this->assertSame('successful', $tx->fresh()->status);
    }

    public function test_admin_assigns_pawapay_to_cameroon_and_client_sends_via_pawapay(): void
    {
        $admin = User::create(['full_name' => 'Super', 'phone' => '242069990123', 'password' => bcrypt('x'), 'status' => 'active']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum');

        $o = $this->getJson('/api/admin/remit/overview')->assertOk();
        $this->assertSame(['wacepay_remit', 'terrapay', 'pawapay'], collect($o->json('partners'))->pluck('key')->all());
        $cm = collect($o->json('countries'))->firstWhere('iso', 'CM');
        $this->assertSame('wacepay_remit', $cm['partner']);
        $this->assertContains('pawapay', $cm['available']);
        $this->assertNotContains('pawapay', collect($o->json('countries'))->firstWhere('iso', 'FR')['available']);

        $this->postJson('/api/admin/remit/countries/FR', ['partner' => 'pawapay'])->assertStatus(422);
        $this->postJson('/api/admin/remit/countries/CM', ['partner' => 'pawapay'])->assertOk();
        $this->postJson('/api/admin/remit/countries/GA', ['partner' => 'off'])->assertOk();
        $o = $this->getJson('/api/admin/remit/overview')->assertOk();
        $this->assertSame('pawapay', collect($o->json('countries'))->firstWhere('iso', 'CM')['partner']);
        $this->assertNull(collect($o->json('countries'))->firstWhere('iso', 'GA')['partner']);
        $this->assertSame(['ok' => true, 'accounts' => [['country' => 'CMR', 'currency' => 'XAF', 'balance' => 500000]]], $this->getJson('/api/admin/remit/balances')->json('pawapay'));

        // Côté client : Cameroun via pawaPay (mobile money seulement), Gabon fermé
        $c = $this->client();
        $this->actingAs($c, 'sanctum');
        $opts = $this->getJson('/api/pay/international/options')->assertOk();
        $this->assertSame(['wallet'], collect($opts->json('countries'))->firstWhere('iso', 'CM')['methods']);
        $this->assertNull(collect($opts->json('countries'))->firstWhere('iso', 'GA'));
        $svc = $this->getJson('/api/pay/international/services?country=CM')->assertOk();
        $this->assertSame('MTN_MOMO_CMR', $svc->json('methods.wallet.1.code'));

        $r = $this->postJson('/api/pay/international/send', [
            'amount' => 50000, 'country' => 'CM', 'method' => 'wallet', 'business_type' => 'p2p',
            'sender' => $this->sender(),
            'beneficiary' => ['first_name' => 'Paul', 'last_name' => 'Biya Nkodo'],
            'account' => ['msisdn' => '670000000'],
            'why' => ['reason' => 'Family Maintainance', 'origin_fund' => 'Salary', 'relation' => 'Brother'],
        ])->assertStatus(202);
        $tx = Transaction::findOrFail($r->json('id'));
        $this->assertSame('pawapay', $tx->destination_rail);

        $send = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/v2/remittances'));
        $this->assertSame('Bearer PTOK', $send->header('Authorization')[0]);
        $this->assertSame('MTN_MOMO_CMR', $send['recipient']['accountDetails']['provider']);
        $this->assertSame('237670000000', $send['recipient']['accountDetails']['phoneNumber']);
        $this->assertSame('50000', $send['amount']);
        $this->assertSame('XAF', $send['currency']);
        $this->assertSame('FAMILY_SUPPORT', $send['sender']['transactionDetails']['purposeOfFunds']);
        $this->assertSame('SALARY', $send['sender']['transactionDetails']['sourceOfFunds']);
        $this->assertSame('PASSPORT', $send['sender']['senderDetails']['identification']['type']);
        $this->assertSame('COG', $send['sender']['senderDetails']['nationality']);
        $this->assertSame($tx->reference, $send['sender']['transactionDetails']['transactionReference']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $send['remittanceId']);

        Artisan::call('remit:sync');
        $this->assertSame('processing', $tx->fresh()->status);
        $this->pawaStatus = 'FAILED';
        Artisan::call('remit:sync');
        $this->assertSame('reversed', $tx->fresh()->status);
        $this->assertSame(500000, (int) $c->wallet->fresh()->balance);
    }

    public function test_activation_profile_is_required_and_expired_id_blocks(): void
    {
        $u = User::create(['full_name' => 'Awa Nouvelle', 'phone' => '242069111222', 'password' => 'secret123', 'kyc_tier' => 2]);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 300000, 'currency' => 'XAF', 'country' => 'CG']);
        $this->actingAs($u, 'sanctum');

        // Profil vide : l'app propose « Activer l'international », le devis est bloqué
        $o = $this->getJson('/api/pay/international/options')->assertOk();
        $this->assertFalse($o->json('profile.complete'));
        $q = $this->postJson('/api/pay/international/quote', ['amount' => 10000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p'])->assertOk();
        $this->assertFalse($q->json('available'));
        $this->assertContains('profile_incomplete', $q->json('problem_codes'));

        // Contrôles : majeur, pièce non expirée
        $this->putJson('/api/pay/international/profile', ['date_of_birth' => now()->subYears(16)->toDateString()])->assertStatus(422);
        $this->putJson('/api/pay/international/profile', ['id_expiry' => now()->subDay()->toDateString()])->assertStatus(422);

        // Enregistrement par étapes
        $this->putJson('/api/pay/international/profile', ['first_name' => 'Awa', 'last_name' => 'Nouvelle', 'gender' => 'F', 'date_of_birth' => '1995-05-05', 'nationality' => 'cg', 'email' => 'awa@test.cg'])->assertOk();
        $this->putJson('/api/pay/international/profile', ['id_type' => 'CNI', 'id_number' => 'CG-778899', 'id_expiry' => now()->addYears(2)->toDateString()])->assertOk();
        $r = $this->putJson('/api/pay/international/profile', ['country' => 'CG', 'city' => 'Pointe-Noire', 'district' => 'Tié-Tié', 'street' => 'Rue 12', 'gps_lat' => -4.79, 'gps_lng' => 11.86])->assertOk();
        $this->assertFalse($r->json('complete'));
        $this->assertSame(['civility', 'pep', 'doc_front', 'doc_back', 'doc_selfie'], array_keys($r->json('missing'))); // CNI : recto + verso + selfie
        // Situation familiale et déclaration PPE : à répondre par le client (« non » est une réponse)
        $this->putJson('/api/pay/international/profile', ['civility' => 'Divorced'])->assertStatus(422);
        $r = $this->putJson('/api/pay/international/profile', ['civility' => 'Single', 'pep' => false])->assertOk();
        $this->assertSame(['doc_front', 'doc_back', 'doc_selfie'], array_keys($r->json('missing')));
        $this->assertFalse($r->json('data.pep'));
        $this->assertSame('Rue 12, Tié-Tié', $r->json('data.address'));
        $this->assertSame('CG', $r->json('data.nationality'));
        $this->assertEqualsWithDelta(-4.79, $r->json('gps.lat'), 0.0001);

        foreach (['id_card', 'id_card_back', 'selfie'] as $t) {
            \App\Models\KycDocument::create(['user_id' => $u->id, 'type' => $t, 'path' => "kyc/{$t}.jpg", 'status' => 'pending']);
        }
        $this->assertTrue($this->getJson('/api/pay/international/profile')->json('complete'));
        // Code postal exigé vers les États-Unis
        $this->assertSame(['postal_code' => 'code postal'], $this->getJson('/api/pay/international/profile?country=US')->json('missing'));

        // E-mail vérifié par code (exigé si REMIT_REQUIRE_EMAIL_VERIFIED)
        config(['flashpay.remit.require_email_verified' => true, 'security.otp_debug' => true]);
        $this->assertArrayHasKey('email_verified', $this->getJson('/api/pay/international/profile')->json('missing'));
        $code = $this->postJson('/api/pay/international/profile/email-code')->assertOk()->json('debug_code');
        $this->postJson('/api/pay/international/profile/email-verify', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/pay/international/profile/email-verify', ['code' => $code])->assertOk()->assertJsonPath('complete', true);

        // Données d'identité chiffrées en base
        $raw = \Illuminate\Support\Facades\DB::table('remit_profiles')->where('user_id', $u->id)->value('data');
        $this->assertStringNotContainsString('CG-778899', $raw);

        // Le devis passe ; l'expéditeur envoyé au partenaire vient du profil, pas de la requête
        $q = $this->postJson('/api/pay/international/quote', ['amount' => 10000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p'])->assertOk();
        $this->assertTrue($q->json('available'), implode(' ', (array) $q->json('problems')));
        $this->postJson('/api/pay/international/send', [
            'amount' => 10000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p', 'service' => ['code' => 'OM', 'name' => 'ORANGE MONEY'],
            'sender' => ['first_name' => 'Faux', 'last_name' => 'Nom', 'id_number' => 'FAKE'],
            'beneficiary' => ['first_name' => 'Koffi', 'last_name' => 'Yao'], 'account' => ['msisdn' => '0707070707'],
        ])->assertStatus(202);
        $sender = collect($this->sent)->first(fn ($q) => str_ends_with($q->url(), '/sender/create'));
        $this->assertSame('Awa', $sender['firstName']);
        $this->assertSame('CG-778899', $sender['idNumber']);
        $this->assertSame('CNI', $sender['idType']);
        $this->assertSame('F', $sender['gender']);
        $this->assertSame('Pointe-Noire', $sender['city']);

        // Pièce expirée ensuite : transfert bloqué
        \App\Models\RemitProfile::where('user_id', $u->id)->first()->forceFill(['data' => array_merge(\App\Models\RemitProfile::where('user_id', $u->id)->first()->data, ['id_expiry' => now()->subDay()->toDateString()])])->save();
        $q = $this->postJson('/api/pay/international/quote', ['amount' => 10000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p'])->assertOk();
        $this->assertContains('id_expired', $q->json('problem_codes'));
        $this->assertTrue($this->getJson('/api/pay/international/options')->json('profile.expired'));
    }
}

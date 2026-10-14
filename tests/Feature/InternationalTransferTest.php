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
                default => Http::response(['message' => 'not found ' . $u], 404),
            };
        });
    }

    protected function client(int $balance = 500000): User
    {
        $u = User::create(['full_name' => 'Basile Test Expediteur', 'phone' => '242069887766', 'password' => 'secret123', 'kyc_tier' => 2]);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => $balance, 'currency' => 'XAF', 'country' => 'CG']);
        return $u->fresh();
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
}

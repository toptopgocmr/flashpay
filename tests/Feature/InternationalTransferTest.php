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
    protected ?array $waceServices = null; // null = liste par défaut ; [] = aucun service ; ['down'] = erreur 500

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
                str_ends_with($u, '/transaction/payouts/services') && $this->waceServices === ['down'] => Http::response(['message' => 'Server Error'], 500),
                str_ends_with($u, '/transaction/payouts/services') && $this->waceServices !== null => Http::response(['status' => 2000, 'data' => $this->waceServices]),
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

    public function test_dedicated_international_tariff_grid_most_specific_rule_wins(): void
    {
        $admin = User::where('phone', '242060000000')->first();
        $this->actingAs($admin, 'sanctum');
        $post = fn (array $t) => $this->postJson('/api/admin/remit-tariffs', $t + ['business_type' => '*', 'method' => '*', 'partner' => '*', 'min_amount' => 0, 'max_amount' => 100000000, 'active' => true]);
        $post(['country' => '*', 'fee_type' => 'percent', 'fee_value' => 3, 'min_fee' => 500])->assertCreated();          // toutes destinations
        $post(['country' => 'CI', 'fee_type' => 'percent', 'fee_value' => 1.5])->assertCreated();                       // Côte d'Ivoire
        $post(['country' => 'CI', 'method' => 'wallet', 'fee_type' => 'fixed', 'fee_value' => 700])->assertCreated();    // CI mobile money
        $post(['country' => 'CI', 'business_type' => 'b2b', 'fee_type' => 'percent', 'fee_value' => 1, 'max_fee' => 2000])->assertCreated();
        $post(['country' => 'CI', 'fee_type' => 'percent', 'fee_value' => 2])->assertStatus(422);                       // chevauchement
        $post(['country' => 'ZZ', 'fee_type' => 'percent', 'fee_value' => 2])->assertStatus(422);                       // pays inconnu

        $idx = $this->getJson('/api/admin/remit-tariffs')->assertOk();
        $this->assertSame(4 + 5, $idx->json('kpi.tiers')); // + 5 paliers de référence par zone
        $this->assertGreaterThan(240, count($idx->json('countries')));
        // La grille générale ne montre plus les transferts internationaux
        $this->assertArrayNotHasKey('international_transfer', $this->getJson('/api/admin/tariffs')->json('operations'));

        $sim = fn (string $c, string $bt, string $m, int $a) => $this->postJson('/api/admin/remit-tariffs/simulate', ['country' => $c, 'business_type' => $bt, 'method' => $m, 'amount' => $a])->json('fee_ht');
        $this->assertSame(700, $sim('CI', 'p2p', 'wallet', 100000));   // pays + réception
        $this->assertSame(1500, $sim('CI', 'p2p', 'bank', 100000));    // pays
        $this->assertSame(2000, $sim('CI', 'b2b', 'bank', 300000));    // pays + type, plafonné
        $this->assertSame(3000, $sim('SN', 'p2p', 'wallet', 100000));  // toutes destinations
        $this->assertSame(6600, $sim('SN', 'p2p', 'wallet', 1000));    // zone Afrique de l'Ouest (1 – 60 000) plus précise que « toutes destinations »
        $this->assertSame(1801, $sim('SN', 'p2p', 'wallet', 60001));   // hors tranche de zone : toutes destinations (3 %)

        // L'app applique la même grille
        $c = $this->client();
        $this->actingAs($c, 'sanctum');
        $q = $this->postJson('/api/pay/international/quote', ['amount' => 100000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p'])->assertOk();
        $this->assertSame(700, $q->json('fee_ht'));
        // TTC = 700 + TVA 18,9 % (132,3) + timbre 50 + COBAC 0,25 % (250) + TTF 1,5 % (1 500) = 2 632
        $this->assertSame(2632, $q->json('fee'));
        $this->assertSame(102632, $q->json('total'));
    }

    public function test_remittance_norms_zones_threshold_taxes_and_exchange_rates(): void
    {
        $admin = User::where('phone', '242060000000')->first();
        $this->actingAs($admin, 'sanctum');
        $idx = $this->getJson('/api/admin/remit-tariffs')->assertOk();
        $this->assertSame(['CEMAC', 'Afrique de l\'Ouest', 'EUROPE', 'World/International'], collect($idx->json('zones'))->pluck('name')->all());
        $this->assertSame(['COBAC', 'TIMBRE', 'TTF', 'TVA'], collect($idx->json('taxes'))->pluck('code')->all());

        $sim = fn (string $c, int $a) => $this->postJson('/api/admin/remit-tariffs/simulate', ['country' => $c, 'business_type' => 'p2p', 'method' => 'wallet', 'amount' => $a])->assertOk();
        // Grille de référence (SEND-PAZ) : CEMAC 0 – 25 000 → HT 2 975, TTC 3 587 à 4 025
        $this->assertSame(2975, $sim('CM', 1)->json('fee_ht'));
        $this->assertSame(3587, $sim('CM', 1)->json('fee'));
        $this->assertSame(4025, $sim('CM', 25000)->json('fee'));
        $this->assertSame(5600, $sim('CM', 25001)->json('fee'));   // 4 300 HT
        $this->assertSame(7897, $sim('SN', 1)->json('fee'));       // Afrique de l'Ouest 6 600 HT
        $this->assertSame(10612, $sim('FR', 60000)->json('fee'));  // Europe 8 000 HT
        $this->assertSame('World/International', $sim('US', 100)->json('zone'));
        $this->assertTrue($sim('SN', 1500000)->json('over_threshold')); // seuil 1 000 000

        // Taux de change à partir du Congo : 1 EUR = 710 XAF
        $eur = collect($idx->json('currencies'))->firstWhere('code', 'EUR');
        $this->postJson('/api/admin/remit-currencies', ['id' => $eur['id'] ?? null, 'code' => 'EUR', 'symbol' => '€', 'xaf_value' => 710, 'active' => true])->assertCreated();
        $this->assertSame(100, $sim('FR', 71000)->json('fx.amount'));

        // Taxe propre à une zone, puis désactivée
        $cemac = collect($idx->json('zones'))->firstWhere('name', 'CEMAC')['id'];
        $tax = $this->postJson('/api/admin/remit-taxes', ['code' => 'TEST', 'label' => 'Test', 'type' => 'fixed', 'value' => 100, 'base' => 'amount', 'zone_id' => $cemac, 'active' => true])->assertCreated()->json('tax');
        $this->assertSame('none', $tax['base']);
        $this->assertSame(3687, $sim('CM', 1)->json('fee'));
        $this->assertSame(7897, $sim('SN', 1)->json('fee'));
        $this->deleteJson('/api/admin/remit-taxes/' . $tax['id'])->assertOk();
        $this->postJson('/api/admin/remit-taxes', ['code' => 'COBAC', 'label' => 'x', 'type' => 'percent', 'value' => 1, 'base' => 'amount'])->assertStatus(422); // code déjà pris

        // Un pays dans une seule zone ; seuil appliqué au devis de l'app
        $this->postJson('/api/admin/remit-zones', ['name' => 'Doublon', 'countries' => ['CM']])->assertStatus(422);
        $wa = collect($idx->json('zones'))->firstWhere('name', 'Afrique de l\'Ouest');
        $this->postJson('/api/admin/remit-zones', ['id' => $wa['id'], 'name' => $wa['name'], 'threshold' => 50000, 'countries' => $wa['countries']])->assertCreated();
        $c = $this->client();
        $this->actingAs($c, 'sanctum');
        $q = $this->postJson('/api/pay/international/quote', ['amount' => 60000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p'])->assertOk();
        $this->assertFalse($q->json('available'));
        $this->assertStringContainsString('Afrique de l\'Ouest', implode(' ', $q->json('problems')));
        $this->assertSame(['COBAC', 'TIMBRE', 'TTF', 'TVA'], collect($q->json('taxes'))->pluck('code')->all());
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

    public function test_unavailable_methods_are_greyed_and_partner_errors_never_500(): void
    {
        $c = $this->client();
        $this->actingAs($c, 'sanctum');

        // Tous les modes proposés
        $s = $this->getJson('/api/pay/international/services?country=CI')->assertOk();
        $this->assertSame(['wallet' => true, 'bank' => true, 'cash' => true], $s->json('available'));

        // WacePay ne propose que le mobile money vers ce pays : banque et cash grisés, devis refusé
        $this->waceServices = [['ServiceCode' => 'OM', 'ServiceName' => 'ORANGE MONEY']];
        Cache::flush();
        $s = $this->getJson('/api/pay/international/services?country=SN')->assertOk();
        $this->assertSame(['wallet' => true, 'bank' => false, 'cash' => false], $s->json('available'));
        $this->assertNotEmpty($s->json('unavailable.bank'));
        $q = $this->postJson('/api/pay/international/quote', ['amount' => 10000, 'country' => 'SN', 'method' => 'bank', 'business_type' => 'p2p'])->assertOk();
        $this->assertFalse($q->json('available'));

        // Le partenaire ne répond pas : 200 avec tout grisé et un message (plus d'« Erreur interne du serveur »)
        $this->waceServices = ['down'];
        Cache::flush();
        $s = $this->getJson('/api/pay/international/services?country=ML')->assertOk();
        $this->assertSame(['wallet' => false, 'bank' => false, 'cash' => false], $s->json('available'));
        $this->assertStringContainsString('indisponibles', (string) $s->json('message'));
        // Banques : saisie libre au lieu de « aucun PayerCode »
        $this->postJson('/api/pay/international/banks', ['country' => 'ML'])->assertOk()->assertJsonPath('free_text', true);

        // Listes WacePay : libellés en français
        $this->waceServices = null;
        $l = $this->getJson('/api/pay/international/lists?business_type=p2p&country=CI')->assertOk();
        $this->assertSame('Soutien familial', $l->json('reasons')['Family Maintainance']);
        $this->assertSame('Frère', $l->json('relations')['Brother']);
    }

    public function test_sender_chooses_the_account_to_debit(): void
    {
        $c = $this->client(0); // wallet vide
        $this->actingAs($c, 'sanctum');
        $o = $this->getJson('/api/pay/international/options')->assertOk();
        $this->assertTrue($o->json('sources.wallet.available'));
        $this->assertSame('+242069887766', $o->json('sources.mobile.accounts.0.phone'));

        $base = ['amount' => 10000, 'country' => 'CI', 'method' => 'wallet', 'business_type' => 'p2p'];
        // Wallet vide : solde insuffisant
        $q = $this->postJson('/api/pay/international/quote', $base)->assertOk();
        $this->assertFalse($q->json('available'));
        // Mobile money du titulaire : pas de contrôle du solde du wallet, frais de collecte ajoutés
        $q = $this->postJson('/api/pay/international/quote', $base + ['source' => ['type' => 'mobile', 'phone' => '+242069887766']])->assertOk();
        $this->assertTrue($q->json('available'), implode(' ', (array) $q->json('problems')));
        $this->assertSame('mobile', $q->json('source.type'));
        $this->assertSame($q->json('fee_remit') + $q->json('collect_fee'), $q->json('fee'));
        // Numéro non lié au profil : refusé
        $q = $this->postJson('/api/pay/international/quote', $base + ['source' => ['type' => 'mobile', 'phone' => '+242055001122']])->assertOk();
        $this->assertFalse($q->json('available'));
        $this->assertStringContainsString('Comptes liés', implode(' ', $q->json('problems')));
        // Carte : aucune carte liée
        config(['payment_methods.card_driver' => 'sandbox']);
        $q = $this->postJson('/api/pay/international/quote', $base + ['source' => ['type' => 'card']])->assertOk();
        $this->assertFalse($q->json('available'));
    }

    public function test_new_id_document_requires_new_photos_and_profile_photo_is_listed(): void
    {
        $c = $this->client();
        $this->actingAs($c, 'sanctum');
        \App\Models\KycDocument::where('user_id', $c->id)->update(['created_at' => now()->subDays(3)]);
        $p = $this->getJson('/api/pay/international/profile')->assertOk();
        $this->assertTrue($p->json('complete'));
        $this->assertSame('profile_photo', $p->json('documents.photo.type'));
        $this->assertFalse($p->json('documents.photo.required'));

        // Pièce renouvelée : nouveau numéro → photos de l'ancienne pièce à renvoyer
        $this->putJson('/api/pay/international/profile', ['id_number' => 'Y987', 'id_expiry' => now()->addYears(5)->toDateString()])->assertOk();
        $p = $this->getJson('/api/pay/international/profile')->assertOk();
        $this->assertFalse($p->json('complete'));
        $this->assertSame('outdated', $p->json('documents.front.status'));
        foreach (['passport', 'selfie'] as $t) {
            \App\Models\KycDocument::create(['user_id' => $c->id, 'type' => $t, 'path' => "kyc/{$t}2.jpg", 'status' => 'pending']);
        }
        $this->assertTrue($this->getJson('/api/pay/international/profile')->json('complete'));
    }
}

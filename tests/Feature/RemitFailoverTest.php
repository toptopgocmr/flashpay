<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Remittance : partenaire principal + liste de secours par pays (Console › Pays & change › Remittance).
 * Le secours prend le relais si le principal n'est pas disponible avant l'envoi, ou s'il refuse le transfert.
 */
class RemitFailoverTest extends TestCase
{
    protected array $sent = [];
    protected bool $fincraRejects = false;
    protected bool $fincraDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Cache::flush();
        config([
            'flashpay.fincra.enabled' => true, 'flashpay.fincra.secret_key' => 'sk', 'flashpay.fincra.public_key' => 'pk',
            'flashpay.fincra.business_id' => 'biz', 'flashpay.fincra.base_url' => 'https://sandboxapi.fincra.test',
            'flashpay.rails.fincra_remit.enabled' => true,
            'flashpay.pawapay.enabled' => true, 'flashpay.pawapay.token' => 'tok', 'flashpay.pawapay.base_url' => 'https://api.sandbox.pawapay.io',
            'flashpay.rails.pawapay.enabled' => true, 'flashpay.remit.min_kyc_tier' => 0, 'flashpay.failover.enabled' => true,
        ]);
        Http::fake(function ($req) {
            $this->sent[] = $req;
            $path = parse_url($req->url(), PHP_URL_PATH);
            if (str_contains($req->url(), 'fincra') && $this->fincraDown) {
                return Http::response('', 503);
            }
            return match (true) {
                $path === '/disbursements/payouts' => $this->fincraRejects
                    ? Http::response(['success' => false, 'error' => 'Invalid beneficiary', 'errorType' => 'VALIDATION_ERROR'], 422)
                    : Http::response(['success' => true, 'data' => ['id' => 1, 'customerReference' => $req['customerReference'], 'status' => 'processing']]),
                $path === '/core/banks' => Http::response(['success' => true, 'data' => [['id' => 1, 'code' => 'MTN', 'name' => 'MTN Mobile Money']]]),
                $path === '/wallets' => Http::response(['success' => true, 'data' => [['currency' => 'XAF', 'availableBalance' => 9000000]]]),
                $path === '/v2/predict-provider' => Http::response(['country' => 'CMR', 'phoneNumber' => preg_replace('/\D/', '', $req['phoneNumber']), 'provider' => 'MTN_MOMO_CMR']),
                $req->method() === 'POST' && $path === '/v2/remittances' => Http::response(['remittanceId' => $req['remittanceId'], 'status' => 'ACCEPTED']),
                str_starts_with($path, '/v2/remittances/') => Http::response(['status' => 'FOUND', 'data' => ['status' => 'PROCESSING']]),
                $path === '/v2/wallet-balances' => Http::response(['balances' => [['country' => 'CMR', 'currency' => 'XAF', 'balance' => '9000000']]]),
                default => Http::response(['message' => 'not found'], 404),
            };
        });
    }

    protected function sender(): array
    {
        return ['first_name' => 'Basile', 'last_name' => 'Test', 'id_type' => 'PP', 'id_number' => 'X123', 'id_expiry' => now()->addYear()->toDateString(),
            'date_of_birth' => '1990-01-01', 'gender' => 'M', 'nationality' => 'CG', 'address' => 'Centre-ville', 'city' => 'Brazzaville'];
    }

    protected function client(): User
    {
        $c = User::create(['full_name' => 'JEAN TEST', 'phone' => '242069887766', 'password' => bcrypt('x'), 'status' => 'active', 'kyc_tier' => 2]);
        $c->assignRole('client');
        Wallet::create(['user_id' => $c->id, 'balance' => 500000, 'currency' => 'XAF', 'country' => 'CG', 'status' => 'active']);
        $this->actingAs($c, 'sanctum')->putJson('/api/pay/international/profile', [
            'first_name' => 'Basile', 'last_name' => 'Test', 'gender' => 'M', 'date_of_birth' => '1990-01-01', 'place_of_birth' => 'Brazzaville',
            'nationality' => 'CG', 'occupation' => 'Informaticien', 'email' => 'basile@test.cg', 'civility' => 'Married', 'pep' => false,
            'id_type' => 'PP', 'id_number' => 'X123', 'id_issuer_country' => 'CG', 'id_issue_date' => '2020-01-01', 'id_expiry' => now()->addYear()->toDateString(),
            'country' => 'CG', 'city' => 'Brazzaville', 'district' => 'Centre-ville', 'street' => 'Avenue de la Paix',
        ])->assertOk();
        foreach (['passport', 'selfie'] as $t) {
            \App\Models\KycDocument::create(['user_id' => $c->id, 'type' => $t, 'path' => "kyc/{$t}.jpg", 'status' => 'pending']);
        }
        return $c->fresh('wallet');
    }

    protected function configure(): void
    {
        $a = User::create(['full_name' => 'ADMIN', 'phone' => '242060000099', 'password' => bcrypt('x'), 'status' => 'active']);
        $a->assignRole('super_admin');
        $this->actingAs($a, 'sanctum');
        $this->postJson('/api/admin/remit/countries/CM', ['partner' => 'fincra_remit'])->assertOk();
        $this->postJson('/api/admin/remit/countries/CM/backups', ['backups' => ['pawapay']])->assertOk();
        $this->postJson('/api/admin/remit/countries/CM/backups', ['backups' => ['fincra_remit', 'nope']])->assertStatus(422);
        $row = collect($this->getJson('/api/admin/remit/overview')->json('countries'))->firstWhere('iso', 'CM');
        $this->assertSame(['fincra_remit', ['pawapay']], [$row['partner'], $row['backups']]);
    }

    protected function send(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/pay/international/send', [
            'amount' => 50000, 'country' => 'CM', 'method' => 'wallet', 'business_type' => 'p2p', 'service' => ['code' => 'MTN', 'name' => 'MTN Mobile Money'],
            'sender' => $this->sender(), 'beneficiary' => ['first_name' => 'Paul', 'last_name' => 'Nkodo'], 'account' => ['msisdn' => '670000000'],
            'why' => ['reason' => 'Family Maintainance', 'origin_fund' => 'Salary', 'relation' => 'Brother'],
        ]);
    }

    public function test_backup_used_when_primary_not_configured(): void
    {
        $this->configure();
        config(['flashpay.fincra.enabled' => false]);
        $c = $this->client();
        $this->assertSame('pawapay', $this->actingAs($c, 'sanctum')->postJson('/api/pay/international/quote', [
            'amount' => 50000, 'country' => 'CM', 'method' => 'wallet', 'business_type' => 'p2p'])->json('partner'));
        $tx = Transaction::findOrFail($this->send()->assertStatus(202)->json('id'));
        $this->assertSame('pawapay', $tx->destination_rail);
        $this->assertTrue(collect($this->sent)->contains(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/v2/remittances'));
    }

    public function test_refused_transfer_switches_to_backup_without_refund(): void
    {
        $this->configure();
        $this->fincraRejects = true;
        $c = $this->client();
        $tx = Transaction::findOrFail($this->actingAs($c, 'sanctum')->send()->assertStatus(202)->json('id'));
        $tx->refresh();
        $this->assertSame('pawapay', $tx->destination_rail);
        $this->assertSame('processing', $tx->status);
        $this->assertSame('fincra_remit', $tx->meta['payout_attempts'][0]['rail']);
        $this->assertArrayNotHasKey('provider', $tx->meta['remit']['account']);
        $this->assertSame('MTN_MOMO_CMR', collect($this->sent)->first(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/v2/remittances')['recipient']['accountDetails']['provider']);
        $this->assertSame(500000 - 50000 - (int) $tx->fee, (int) $c->wallet->fresh()->balance);
    }

    public function test_primary_not_responding_before_debit_uses_backup(): void
    {
        $this->configure();
        $c = $this->client();
        $this->fincraDown = true;
        Cache::flush();
        $tx = Transaction::findOrFail($this->actingAs($c, 'sanctum')->send()->assertStatus(202)->json('id'));
        $this->assertSame('pawapay', $tx->destination_rail);
    }
}

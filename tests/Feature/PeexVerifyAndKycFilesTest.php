<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * - Verify Wallet : seul un « compte introuvable » explicite bloque ; une route
 *   absente, un 422 ou une réponse sans verdict ne rejettent plus un vrai numéro.
 * - Pièces KYC : servies depuis la base si le disque a été effacé (Railway),
 *   consultables par leur propriétaire (aperçu mobile) et par la console.
 */
class PeexVerifyAndKycFilesTest extends TestCase
{
    protected string $mode = 'route_missing';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.peex.verify_accounts' => true, 'flashpay.peex.check_balance' => false, 'flashpay.peex.secret_key' => 'k']);

        Http::fake(function (HttpRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_contains($path, 'verify_wallet') || str_contains($path, 'verify-wallet')) {
                return match ($this->mode) {
                    'route_missing' => Http::response(['error' => ['statusCode' => 404, 'name' => 'NotFoundError', 'message' => 'Endpoint "POST ' . $path . '" not found.']], 404),
                    'dash_ok' => str_contains($path, 'verify-wallet')
                        ? Http::response(['isValid' => true, 'accountName' => 'JEAN MOUKALA', 'status' => 'ACTIVE'])
                        : Http::response(['error' => ['statusCode' => 404, 'message' => 'Endpoint not found']], 404),
                    'unprocessable' => Http::response(['error' => ['statusCode' => 400, 'message' => 'invalid test number']], 400),
                    'no_verdict' => Http::response(['message' => 'ok']),
                    // Réponse réelle de PEEX production (29/09/2026)
                    'prod_cg' => Http::response(['valid' => false, 'message' => 'Unsupported account code: CG']),
                    'not_found' => Http::response(['error' => ['statusCode' => 404, 'message' => 'Account not found on the provider network']], 404),
                };
            }
            if (str_contains($path, 'verify_phoneNumber')) {
                return Http::response(['valid' => true, 'mnc' => 'mtn-cg']);
            }
            if (str_contains($path, 'disbursement/me')) {
                // PEEX production : solde de décaissement non communiqué
                return Http::response(['is_activated' => true, 'disbursement_solde' => null, 'mtn_fees' => 0]);
            }
            return Http::response(['ok' => true, 'is_activated' => true]);
        });
    }

    protected function client(string $phone): User
    {
        $u = User::create(['full_name' => 'Client ' . $phone, 'phone' => $phone, 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 5000, 'currency' => 'XAF', 'country' => 'CG']);
        return $u->fresh();
    }

    protected function quote(): array
    {
        $u = User::where('phone', '242061000601')->first() ?? $this->client('242061000601');
        $this->actingAs($u, 'sanctum');
        return $this->postJson('/api/pay/quote', ['operation' => 'deposit', 'source_phone' => '+242067601919', 'amount' => 100])->assertOk()->json();
    }

    public function test_missing_route_does_not_reject_real_number(): void
    {
        $this->mode = 'route_missing';
        $q = $this->quote();
        $this->assertStringNotContainsString("n'est pas un compte mobile money actif", implode(' ', $q['problems'] ?? []));
    }

    public function test_alternate_path_is_used(): void
    {
        $this->mode = 'dash_ok';
        $q = $this->quote();
        $this->assertStringNotContainsString("n'est pas un compte", implode(' ', $q['problems'] ?? []));
    }

    public function test_400_and_empty_answer_are_not_a_rejection(): void
    {
        foreach (['unprocessable', 'no_verdict'] as $m) {
            $this->mode = $m;
            \Illuminate\Support\Facades\Cache::flush();
            $q = $this->quote();
            $this->assertStringNotContainsString("n'est pas un compte", implode(' ', $q['problems'] ?? []), $m);
        }
    }

    public function test_production_cg_answer_and_null_payout_balance_do_not_block(): void
    {
        $this->mode = 'prod_cg';
        config(['flashpay.peex.check_balance' => true]);
        $u = $this->client('242061000610');
        $this->actingAs($u, 'sanctum');
        $q = $this->postJson('/api/pay/quote', ['operation' => 'transfer', 'source' => 'mobile', 'source_phone' => '+242067601919',
            'destination_phone' => '+242055212223', 'deliver_to' => 'mobile', 'amount' => 100])->assertOk()->json();
        $this->assertTrue($q['available'], implode(' ', $q['problems'] ?? []));

        config(['flashpay.peex.require_payout_balance' => true]);
        \Illuminate\Support\Facades\Cache::flush();
        $q = $this->postJson('/api/pay/quote', ['operation' => 'transfer', 'source' => 'mobile', 'source_phone' => '+242067601919',
            'destination_phone' => '+242055212223', 'deliver_to' => 'mobile', 'amount' => 100])->assertOk()->json();
        $this->assertFalse($q['available']);
    }

    public function test_explicit_account_not_found_still_blocks(): void
    {
        $this->mode = 'not_found';
        $q = $this->quote();
        $this->assertStringContainsString("n'est pas un compte mobile money actif", implode(' ', $q['problems'] ?? []));
    }

    public function test_kyc_photo_is_served_from_database_when_disk_is_wiped(): void
    {
        Storage::fake('local');
        $u = $this->client('242061000602');
        $this->actingAs($u, 'sanctum');
        $this->post('/api/kyc/documents', ['type' => 'profile_photo', 'file' => UploadedFile::fake()->image('p.jpg', 40, 40)])->assertCreated();
        $doc = KycDocument::firstOrFail();

        Storage::disk('local')->delete($doc->path); // redéploiement Railway
        $this->assertFalse(Storage::disk('local')->exists($doc->path));

        $r = $this->get('/api/me/photo')->assertOk();
        $this->assertStringStartsWith('image/', $r->headers->get('Content-Type'));
        $this->get("/api/kyc/documents/{$doc->id}/file")->assertOk();
        $this->assertTrue($this->getJson('/api/kyc')->json('has_photo'));
        $this->assertArrayNotHasKey('content', $this->getJson('/api/kyc')->json('documents.0'));

        // Fichier perdu (ni disque ni base) : l'app le signale, la console demande un nouvel envoi
        $doc->forceFill(['content' => null])->save();
        $this->assertFalse($this->getJson('/api/kyc')->json('documents.0.has_file'));
        $admin = User::create(['full_name' => 'Admin', 'phone' => '242060009999', 'password' => 'secret123']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum');
        $this->postJson("/api/support/desk/kyc/documents/{$doc->id}/request-resend")->assertOk();
        $this->assertSame('rejected', $doc->fresh()->status);
        $this->actingAs($u, 'sanctum');

        $other = $this->client('242061000603');
        $this->actingAs($other, 'sanctum');
        $this->get("/api/kyc/documents/{$doc->id}/file")->assertForbidden();
    }
}

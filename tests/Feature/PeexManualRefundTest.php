<?php

namespace Tests\Feature;

use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Peex\PeexStatusHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PeexManualRefundTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.peex.secret_key' => 'k', 'flashpay.peex.verify_accounts' => false, 'flashpay.peex.check_balance' => false]);
    }

    protected function admin(): User
    {
        $u = User::create(['full_name' => 'Super', 'phone' => '242069990001', 'password' => bcrypt('x'), 'status' => 'active']);
        $u->assignRole('super_admin');
        return $u;
    }

    protected function failedTx(): Transaction
    {
        return Transaction::create(['reference' => 'FP-' . Str::upper(Str::random(12)), 'type' => 'p2p', 'source_rail' => 'peex', 'destination_rail' => 'peex',
            'source_account' => '242067601919', 'destination_account' => '242057563644', 'amount' => 1000, 'fee' => 10, 'currency' => 'XAF',
            'status' => 'failed', 'initiated_by' => 1, 'meta' => ['payer_verified_name' => 'BASILE NGASSAKI']]);
    }

    public function test_bank_and_mobile_refunds_are_sent_and_tracked(): void
    {
        $sent = [];
        Http::fake(function ($req) use (&$sent) {
            $sent[] = [$req->url(), $req->data()];
            $track = $req->data()['track_id'] ?? 'x';
            return Http::response(['request' => ['id' => 9, 'status' => 'new', 'track_id' => $track]], 200);
        });
        $admin = $this->admin();
        $tx = $this->failedTx();

        $info = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/transactions/{$tx->id}/refunds")->assertOk()->json();
        $this->assertSame(1010, $info['refundable']);
        $this->assertSame('+242067601919', $info['defaults']['phone']);

        // Banque : IBAN / SWIFT obligatoires
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refunds", ['channel' => 'bank', 'amount' => 500, 'reason' => 'test', 'beneficiary_name' => 'Basile N'])
            ->assertStatus(422)->assertJsonValidationErrors(['bank_iban', 'bank_swift', 'bank_address', 'to_country']);

        $r = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refunds", [
            'channel' => 'bank', 'amount' => 500, 'reason' => 'Versement échoué', 'beneficiary_name' => 'Basile Ngassaki',
            'bank_name' => 'BGFI', 'bank_address' => 'Brazzaville', 'bank_iban' => 'CG39 3001 1000 1012 3456 7890 123', 'bank_swift' => 'bgficgcg', 'to_country' => 'cg',
        ])->assertCreated()->json();
        $this->assertSame($tx->reference . '-M1', $r['track_id']);
        [$url, $body] = end($sent);
        $this->assertStringEndsWith('clients/request_bank_payment', $url);
        $this->assertSame('bank', $body['transaction_type']);
        $this->assertSame('BGFICGCG', $body['bank_swift']);
        $this->assertSame('CG', $body['to_country']);
        $this->assertSame(1, $body['aml_cft']);

        // Plafond : reste 510
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refunds", ['channel' => 'mobile', 'amount' => 600, 'reason' => 'x', 'beneficiary_name' => 'B N', 'phone' => '+242067601919'])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $m = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refunds", ['channel' => 'mobile', 'amount' => 510, 'reason' => 'x', 'beneficiary_name' => 'B N', 'phone' => '+242067601919'])
            ->assertCreated()->json();
        $this->assertSame($tx->reference . '-M2', $m['track_id']);

        // Finalisation : la transaction d'origine n'est pas modifiée
        $req = PeexRequest::where('track_id', $tx->reference . '-M1')->first();
        $req->update(['status' => 'paid']);
        app(PeexStatusHandler::class)->finalize($req, true);
        $this->assertSame('failed', $tx->fresh()->status);
        $this->assertNotNull($req->fresh()->finalized_at);

        // Liste console : opérateur / passerelle
        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/transactions')->assertOk()->json('data.0');
        $this->assertSame('PEEX', $list['gateway']['in']['partner']);
        $this->assertNotEmpty($list['gateway']['in']['operator']);
        $this->assertContains($tx->reference . '-M1', $list['gateway']['track_ids']);
        $this->assertSame(0, $list['refundable']);
    }

    public function test_official_peex_form_sends_exact_fields(): void
    {
        $sent = [];
        Http::fake(function ($req) use (&$sent) {
            $sent[] = [$req->url(), $req->data()];
            return Http::response(['request' => ['id' => 11, 'status' => 'new', 'track_id' => $req->data()['track_id'] ?? 'x']], 200);
        });
        $admin = $this->admin();
        $tx = $this->failedTx();

        $info = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/transactions/{$tx->id}/refunds")->assertOk()->json();
        $this->assertSame('BASILE', $info['peex']['first_name']);
        $this->assertSame(1010, $info['peex']['amount']);

        $base = ['amount' => 400, 'reason' => 'Versement échoué', 'sender_first_name' => 'FlashPay', 'sender_last_name' => 'Remboursement',
            'sender_mobile_phone' => '+242060000000', 'first_name' => 'Basile', 'last_name' => 'Ngassaki', 'purpose' => 'REFUND', 'fund_origin' => 'SALARY'];

        // Remittance : aml_cft obligatoire
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refunds", $base + ['api' => 'remittance', 'mobile_phone' => '067601919',
            'from_currency' => 'XAF', 'fxrate' => 1, 'sender_country' => 'CG', 'to_country' => 'CG'])
            ->assertStatus(422)->assertJsonValidationErrors(['aml_cft']);

        $r = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refunds", $base + ['api' => 'remittance', 'mobile_phone' => '067601919',
            'from_currency' => 'XAF', 'to_currency' => 'XAF', 'fxrate' => 1, 'aml_cft' => true, 'sender_country' => 'CG', 'to_country' => 'CG', 'sender_city' => 'Brazzaville'])
            ->assertCreated()->json();
        [$url, $body] = end($sent);
        $this->assertStringEndsWith('clients/request_payment', $url);
        $this->assertSame($tx->reference . '-M1', $r['track_id']);
        $this->assertSame('+242067601919', $body['mobile_phone']);
        $this->assertSame(1, $body['aml_cft']);
        $this->assertSame('Basile', $body['first_name']);
        $this->assertSame('REFUND', $body['purpose']);
        $this->assertSame('Brazzaville', $body['sender_city']);

        // Disbursement : currency + country
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/transactions/{$tx->id}/refunds", $base + ['api' => 'disbursement', 'amount' => 100,
            'mobile_phone' => '067601919', 'currency' => 'XAF', 'country' => 'CG'])->assertCreated();
        [$url, $body] = end($sent);
        $this->assertStringEndsWith('disbursement/request_payment', $url);
        $this->assertSame('CG', $body['country']);
        $this->assertSame('XAF', $body['currency']);
        $this->assertArrayNotHasKey('aml_cft', $body);
    }
}

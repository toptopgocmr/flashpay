<?php

namespace Tests\Feature;

use App\Services\Fincra\FincraClient;
use App\Services\Maishapay\MaishapayClient;
use App\Services\Remit\RemitException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Une opération n'est « introuvable » (donc échouée après le délai, puis basculée ou remboursée)
 * que si le partenaire le DIT. Un 404 au corps vide (mauvaise URL, panne) ou une réponse au
 * statut illisible ne doivent jamais faire croire à un échec : risque de double versement.
 */
class PartnerStatusSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'flashpay.maishapay.enabled' => true, 'flashpay.maishapay.public_key' => 'MP-SBPK-t', 'flashpay.maishapay.secret_key' => 'MP-SBSK-t',
            'flashpay.maishapay.base_url' => 'https://mp.test/api',
            'flashpay.fincra.enabled' => true, 'flashpay.fincra.secret_key' => 'sk', 'flashpay.fincra.public_key' => 'pk',
            'flashpay.fincra.business_id' => 'biz', 'flashpay.fincra.base_url' => 'https://fincra.test',
        ]);
    }

    public function test_maishapay_empty_404_is_uncertain_not_not_found(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->expectException(RemitException::class);
        app(MaishapayClient::class)->check('FP-1', true);
    }

    public function test_maishapay_explicit_not_found_and_unreadable_status(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Transaction not found'], 404)
            ->push(['status_code' => 200, 'something' => 'else'], 200)
            ->push(['message' => 'Route not found'], 404)]);
        $c = app(MaishapayClient::class);
        $this->assertFalse($c->check('FP-1', true)['found']);
        $r = $c->check('FP-2', true);
        $this->assertTrue($r['found']);
        $this->assertSame('pending', $r['status']);
        $this->expectException(RemitException::class);
        $c->check('FP-3', true);
    }

    public function test_fincra_empty_404_is_uncertain_and_unreadable_status_stays_pending(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 404)
            ->push(['message' => 'Payout not found'], 404)
            ->push(['status' => true, 'data' => ['foo' => 'bar']], 200)]);
        $c = app(FincraClient::class);
        try {
            $c->payoutStatus('FP-1');
            $this->fail('Un 404 vide doit rester incertain');
        } catch (RemitException) {
            $this->addToAssertionCount(1);
        }
        $this->assertFalse($c->payoutStatus('FP-2')['found']);
        $r = $c->payoutStatus('FP-3');
        $this->assertTrue($r['found']);
        $this->assertSame('pending', $r['status']);
    }
}

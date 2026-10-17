<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Ops\PlatformSettings;
use App\Support\PartnerFees;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Frais partenaires inclus dans le total avant l'opération ; tous les pays du monde. */
class PartnerFeesQuoteWorldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.peex.verify_accounts' => false, 'flashpay.peex.check_balance' => false, 'flashpay.peex.secret_key' => 'k', 'flashpay.rails.peex.enabled' => true]);
        Http::fake(fn () => Http::response(['name' => 'x', 'is_activated' => true]));
    }

    public function test_partner_fees_are_added_to_total_before_operation(): void
    {
        $rates = PartnerFees::rates();
        $saved = PartnerFees::sanitize(array_map(fn ($r) => ['pct' => $r['pct'], 'fixed' => $r['fixed'], 'countries' => (array) $r['countries']], $rates));
        $saved['peex_payout'] = ['pct' => 1, 'fixed' => 0, 'countries' => []];
        app(PlatformSettings::class)->set('partner_fees', $saved);

        $u = User::create(['full_name' => 'BASILE ZONI', 'phone' => '242061000551', 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 50000, 'currency' => 'XAF', 'country' => 'CG']);
        $this->actingAs($u->fresh(), 'sanctum');

        // Mobile → mobile : collecte PEEX 2,5 % + versement PEEX 1 %
        $q = $this->postJson('/api/pay/quote', ['operation' => 'transfer', 'source' => 'mobile', 'source_phone' => '+242061000551',
            'destination_phone' => '+242055000552', 'deliver_to' => 'mobile', 'amount' => 10000, 'beneficiary_name' => 'JEAN MOUKALA'])->assertOk()->json();
        $this->assertSame(100, $q['partner_fees']['payout']);           // 1 % de 10 000
        $base = 10000 + $q['fee_flashpay'] + 100;
        $this->assertSame((int) ceil(round($base * 0.025 / 0.975, 6)), $q['partner_fees']['collect']);
        $this->assertSame($q['fee_flashpay'] + $q['partner_fees']['collect'] + 100, $q['fee']);
        $this->assertSame(10000 + $q['fee'], $q['total']);
        $this->assertSame('PEEX', $q['partner_fees']['collect_partner']);
        // La collecte couvre bien ses propres frais : (total) × 2,5 % ≤ frais de collecte
        $this->assertLessThanOrEqual($q['partner_fees']['collect'], (int) ceil($q['total'] * 0.025));

        // Wallet → wallet : aucun partenaire, aucun frais partenaire
        $v = User::create(['full_name' => 'JEAN', 'phone' => '242069990003', 'password' => 'secret123']);
        $v->assignRole('client');
        Wallet::create(['user_id' => $v->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        $w = $this->postJson('/api/pay/quote', ['operation' => 'transfer', 'source' => 'wallet', 'destination_phone' => '+242069990003',
            'deliver_to' => 'wallet', 'amount' => 10000])->assertOk()->json();
        $this->assertSame(0, $w['partner_fees']['collect'] + $w['partner_fees']['payout']);
        $this->assertSame($w['fee_flashpay'], $w['fee']);
    }

    public function test_all_world_countries_for_signup_corridors_and_remittance(): void
    {
        $c = app(\App\Services\Peex\PeexCorridors::class);
        $this->assertGreaterThan(240, count($c->catalog()));
        $this->assertSame('FR', $c->resolve('+33612345678')['country']);
        $this->assertSame('US', $c->resolve('+12015550123')['country']);   // +1 → pays principal
        $this->assertSame('DE', $c->resolve('+4915123456789')['country']); // longueur variable (10–11)
        $this->assertSame('CG', $c->resolve('061234567')['country']);      // inchangé
        $this->assertSame('international', $c->scope('FR', 'US'));
        $this->assertSame('regional', $c->scope('FR', 'BE'));

        // Inscription avec un numéro français
        $reg = $this->postJson('/api/auth/register', ['full_name' => 'Marie Dupont', 'phone' => '+33612345678', 'password' => '7391', 'password_confirmation' => '7391', 'profile' => 'client']);
        $this->assertContains($reg->status(), [200, 201], $reg->getContent());
        $this->assertTrue(User::where('phone', '33612345678')->exists());
        // EUR pas encore géré pour les wallets : wallet ouvert en devise de base
        $this->assertSame('XAF', User::where('phone', '33612345678')->first()->wallet->currency);

        // App : les pays du monde encore fermés ne sont pas proposés ; console : tous
        $app = $this->getJson('/api/pay/corridors')->json('corridors');
        $this->assertFalse(collect($app)->contains('country', 'FR'));
        $a = User::where('phone', '242060000000')->first();
        $admin = $this->actingAs($a, 'sanctum')->getJson('/api/admin/corridors')->assertOk()->json('corridors');
        $fr = collect($admin)->firstWhere('country', 'FR');
        $this->assertSame('world', $fr['source']);
        $this->assertFalse($fr['collect']);
        $this->actingAs($a, 'sanctum')->postJson('/api/admin/corridors/FR', ['payout_partner' => 'peex'])->assertStatus(422);

        // Remittance : tous les pays
        $this->assertGreaterThan(240, count(\App\Services\Remit\RemitCountries::all()));
        $this->assertSame('JPY', \App\Services\Remit\RemitCountries::get('JP')['currency']);
        $this->assertSame('USD', \App\Services\Remit\RemitCountries::get('CD')['currency']); // valeur de la liste prioritaire conservée
    }
}

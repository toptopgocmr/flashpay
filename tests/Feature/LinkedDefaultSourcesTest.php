<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Comptes liés : un compte débité par défaut PAR TYPE (mobile money, carte). */
class LinkedDefaultSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Http::fake(fn () => Http::response(['valid' => true, 'mnc' => 'mtn-cg']));
    }

    public function test_default_is_kept_per_type_and_can_be_changed(): void
    {
        $u = User::create(['full_name' => 'Client D', 'phone' => '242061000701', 'password' => 'secret123']);
        $u->assignRole('client');
        Wallet::create(['user_id' => $u->id, 'balance' => 0, 'currency' => 'XAF', 'country' => 'CG']);
        $this->actingAs($u, 'sanctum');

        $m1 = $this->postJson('/api/linked-accounts', ['type' => 'mobile_money', 'phone' => '067601919', 'country' => 'CG'])->assertCreated()->json();
        $card = $this->postJson('/api/linked-accounts', ['type' => 'card', 'card_holder' => 'Client D', 'card_number' => '4111 1111 1111 1111', 'card_expiry' => '11/28'])->assertCreated()->json();
        $m2 = $this->postJson('/api/linked-accounts', ['type' => 'mobile_money', 'phone' => '055212223', 'country' => 'CG'])->assertCreated()->json();

        // Le 1er de chaque type est débité par défaut ; ajouter une carte ne retire pas le défaut mobile money
        $this->assertTrue($m1['is_default']);
        $this->assertTrue($card['is_default']);
        $this->assertFalse($m2['is_default']);
        $this->assertSame('Visa', $card['card_brand']);

        $this->postJson("/api/linked-accounts/{$m2['id']}/default")->assertOk();
        $all = collect($this->getJson('/api/linked-accounts')->json())->keyBy('id');
        $this->assertTrue($all[$m2['id']]['is_default']);
        $this->assertFalse($all[$m1['id']]['is_default']);
        $this->assertTrue($all[$card['id']]['is_default']);

        // Supprimer le défaut : l'autre mobile money le devient
        $this->deleteJson("/api/linked-accounts/{$m2['id']}")->assertOk();
        $all = collect($this->getJson('/api/linked-accounts')->json())->keyBy('id');
        $this->assertTrue($all[$m1['id']]['is_default']);
    }
}

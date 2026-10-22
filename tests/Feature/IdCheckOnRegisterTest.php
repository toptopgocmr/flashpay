<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Compliance\IdDocumentCheck;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Inscription client : la pièce (bande MRZ) doit correspondre au nom et à la date de naissance saisis, sinon refus. */
class IdCheckOnRegisterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        config(['flashpay.kyc.id_check_on_register' => true]);
    }

    protected static function cd(string $s): string
    {
        $w = [7, 3, 1];
        $sum = 0;
        foreach (str_split($s) as $i => $c) {
            $sum += (ctype_digit($c) ? (int) $c : ($c === '<' ? 0 : ord($c) - 55)) * $w[$i % 3];
        }
        return (string) ($sum % 10);
    }

    /** Passeport (TD3) valide. */
    protected static function passport(string $surname, string $given, string $dob, string $exp, string $num = 'OA1234567'): string
    {
        $l1 = str_pad('P<COG' . $surname . '<<' . str_replace(' ', '<', $given), 44, '<');
        $n = $num . self::cd($num);
        $d = $dob . self::cd($dob);
        $e = $exp . self::cd($exp);
        $opt = str_repeat('<', 14);
        $o = $opt . self::cd($opt);
        $l2 = $n . 'COG' . $d . 'M' . $e . $o;
        return "REPUBLIQUE DU CONGO\nPASSEPORT\n" . $l1 . "\n" . substr($l2, 0, 22) . ' ' . substr($l2, 22) . self::cd($n . $d . $e . $o);
    }

    /** Carte d'identité (TD1) valide. */
    protected static function idCard(string $surname, string $given, string $dob, string $exp): string
    {
        $num = 'CG1234567';
        $l1 = 'I<COG' . $num . self::cd($num) . str_repeat('<', 15);
        $l2a = $dob . self::cd($dob) . 'F' . $exp . self::cd($exp) . 'COG' . str_repeat('<', 11);
        $l2 = $l2a . self::cd(substr($l1, 5, 25) . substr($l2a, 0, 7) . substr($l2a, 8, 7) . substr($l2a, 18, 11));
        $l3 = str_pad($surname . '<<' . str_replace(' ', '<', $given), 30, '<');
        return "$l1\n$l2\n$l3";
    }

    public function test_parser_reads_passport_and_card_and_tolerates_ocr_noise(): void
    {
        $c = new IdDocumentCheck();
        $p = $c->parse(str_replace('0', 'O', self::passport('MAKAYA', 'KLOE JENNIFER', '950312', '330101')));
        $this->assertSame(['TD3', 'MAKAYA', 'KLOE JENNIFER', '1995-03-12', '2033-01-01', true], [$p['format'], $p['surname'], $p['given_names'], $p['date_of_birth'], $p['expiry'], $p['checks_ok']]);
        $card = $c->parse("RECTO\nNOM: BAKOTE", self::idCard('BAKOTE', 'JENNIFER', '010203', '310630'));
        $this->assertSame(['TD1', 'BAKOTE', 'JENNIFER', '2001-02-03', true], [$card['format'], $card['surname'], $card['given_names'], $card['date_of_birth'], $card['checks_ok']]);
        $this->assertTrue($c->sameName('Jennifer Bakoté', 'BAKOTE', 'JENNIFER'));
        $this->assertTrue($c->sameName('Bakote-Ngoma Jennifer', 'BAKOTE<NGOMA', 'JENNIFER'));
        $this->assertFalse($c->sameName('Jennifer Bakote Paul', 'BAKOTE', 'JENNIFER'));
        $this->assertFalse($c->sameName('Paul Bakote', 'BAKOTE', 'JENNIFER'));
        $this->assertNull($c->parse('Carte nationale d\'identité sans bande MRZ'));
    }

    public function test_register_refused_when_id_does_not_match_then_accepted_when_it_matches(): void
    {
        $base = ['phone' => '242061112233', 'full_name' => 'Kloe Makaya', 'date_of_birth' => '1995-03-12'];

        // Pas de pièce : inscription refusée
        $this->postJson('/api/auth/register', $base + ['password' => '4827', 'password_confirmation' => '4827', 'profile' => 'client'])
            ->assertStatus(422)->assertJsonValidationErrors('id_check_token');

        // Nom différent / date différente / pièce expirée / illisible : refus
        $this->postJson('/api/auth/register/verify-id', $base + ['id_text_front' => self::passport('MAKAYA', 'CHLOE', '950312', '330101')])
            ->assertStatus(422)->assertJsonPath('code', 'id_mismatch');
        $this->postJson('/api/auth/register/verify-id', $base + ['id_text_front' => self::passport('MAKAYA', 'KLOE', '950313', '330101')])->assertStatus(422);
        $this->postJson('/api/auth/register/verify-id', $base + ['id_text_front' => self::passport('MAKAYA', 'KLOE', '950312', '200101')])->assertStatus(422);
        $this->postJson('/api/auth/register/verify-id', $base + ['id_text_front' => 'photo floue'])->assertStatus(422);
        $this->assertSame(0, User::where('phone', '242061112233')->count());

        // Pièce conforme : jeton, puis inscription
        $tok = $this->postJson('/api/auth/register/verify-id', $base + ['id_text_back' => self::passport('MAKAYA', 'KLOE', '950312', '330101')])
            ->assertOk()->assertJsonPath('document.type', 'passport')->json('id_check_token');
        // Jeton lié aux données : un autre nom est refusé
        $this->postJson('/api/auth/register', array_merge($base, ['full_name' => 'Autre Nom']) + ['password' => '4827', 'password_confirmation' => '4827', 'profile' => 'client', 'id_check_token' => $tok])
            ->assertStatus(422);
        $this->postJson('/api/auth/register', $base + ['password' => '4827', 'password_confirmation' => '4827', 'profile' => 'client', 'id_check_token' => $tok])->assertCreated();
        $this->assertSame('OA1234567', User::where('phone', '242061112233')->value('id_number'));
    }
}

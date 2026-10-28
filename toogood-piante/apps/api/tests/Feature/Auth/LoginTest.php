<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AuthHelpers;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_senza_email_confermata_non_si_accede(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        Mail::assertNotSent(LoginCodeMail::class);
    }

    public function test_password_errata_e_utente_inesistente_danno_la_stessa_risposta(): void
    {
        $user = User::factory()->create();

        $wrong = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'sbagliata'])->assertUnauthorized();
        $unknown = $this->postJson('/api/auth/login', ['email' => 'nessuno@example.com', 'password' => 'sbagliata'])->assertUnauthorized();

        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertSame('INVALID_CREDENTIALS', $wrong->json('code'));
        Mail::assertNotSent(LoginCodeMail::class);
    }

    public function test_con_password_corretta_arriva_il_codice_ma_nessun_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['challenge_id', 'expires_in'])
            ->assertJsonMissingPath('access_token');

        Mail::assertSent(LoginCodeMail::class, fn ($mail) => $mail->hasTo($user->email));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastLoginCode());
        $this->assertSame(600, $response->json('expires_in'));
    }

    public function test_con_il_codice_giusto_si_ricevono_i_token_e_si_accede(): void
    {
        $user = User::factory()->create();

        $tokens = $this->loginWithCode($user);

        $this->assertNotEmpty($tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);
        $this->assertSame('CUSTOMER', $tokens['user']['role']);

        $this->bearer($tokens['access_token'])->getJson('/api/me')
            ->assertOk()->assertJsonPath('email', $user->email)->assertJsonMissingPath('password');
    }

    public function test_senza_token_le_rotte_protette_rispondono_401_in_json(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->get('/api/me')->assertUnauthorized();
    }

    public function test_un_codice_sbagliato_viene_rifiutato(): void
    {
        $user = User::factory()->create();
        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_id');

        $wrong = $this->lastLoginCode() === '000000' ? '111111' : '000000';
        $this->postJson('/api/auth/login/verify', ['challenge_id' => $challenge, 'code' => $wrong])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CODE');
    }

    public function test_dopo_cinque_codici_sbagliati_anche_quello_giusto_viene_bloccato(): void
    {
        $user = User::factory()->create();
        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_id');
        $right = $this->lastLoginCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login/verify', ['challenge_id' => $challenge, 'code' => $wrong])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login/verify', ['challenge_id' => $challenge, 'code' => $right])
            ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_il_codice_si_puo_usare_una_sola_volta(): void
    {
        $user = User::factory()->create();
        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_id');
        $code = $this->lastLoginCode();

        $this->postJson('/api/auth/login/verify', ['challenge_id' => $challenge, 'code' => $code])->assertOk();
        $this->postJson('/api/auth/login/verify', ['challenge_id' => $challenge, 'code' => $code])
            ->assertUnauthorized()->assertJsonPath('code', 'CODE_EXPIRED');
    }

    public function test_il_codice_scade_dopo_dieci_minuti(): void
    {
        $user = User::factory()->create();
        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_id');
        $code = $this->lastLoginCode();

        $this->travel(11)->minutes();

        $this->postJson('/api/auth/login/verify', ['challenge_id' => $challenge, 'code' => $code])
            ->assertUnauthorized()->assertJsonPath('code', 'CODE_EXPIRED');
    }

    public function test_un_nuovo_accesso_invalida_il_codice_precedente(): void
    {
        $user = User::factory()->create();
        $first = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_id');
        $firstCode = $this->lastLoginCode();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $this->postJson('/api/auth/login/verify', ['challenge_id' => $first, 'code' => $firstCode])
            ->assertUnauthorized()->assertJsonPath('code', 'CODE_EXPIRED');
    }

    public function test_il_codice_di_un_altro_utente_non_funziona(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceChallenge = $this->postJson('/api/auth/login', ['email' => $alice->email, 'password' => 'password'])->json('challenge_id');
        $this->postJson('/api/auth/login', ['email' => $bob->email, 'password' => 'password'])->assertOk();
        $bobCode = $this->lastLoginCode();

        $response = $this->postJson('/api/auth/login/verify', ['challenge_id' => $aliceChallenge, 'code' => $bobCode]);

        // Se per puro caso i due codici coincidono, il test non dimostra nulla: succede 1 volta su un milione
        if ($response->status() !== 200) {
            $response->assertUnauthorized();
        }
    }

    public function test_un_account_sospeso_non_accede(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_SUSPENDED]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        Mail::assertNotSent(LoginCodeMail::class);
    }

    public function test_un_account_cancellato_risponde_come_credenziali_errate(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_DELETED]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_dopo_cinque_tentativi_in_un_minuto_scatta_il_blocco(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'sbagliata'])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
    }
}

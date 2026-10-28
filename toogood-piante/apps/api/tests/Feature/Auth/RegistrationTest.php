<?php

namespace Tests\Feature\Auth;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AuthHelpers;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_un_cliente_si_registra_e_riceve_l_email_di_conferma(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonPath('user.role', 'CUSTOMER')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', ['email' => 'mario@example.com', 'role' => 'CUSTOMER', 'status' => 'ACTIVE']);
        Mail::assertSent(VerifyEmailMail::class, fn ($mail) => $mail->hasTo('mario@example.com'));
    }

    public function test_un_venditore_si_registra_con_il_ruolo_venditore(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload(['role' => 'seller']))
            ->assertCreated()
            ->assertJsonPath('user.role', 'SELLER');
    }

    public function test_non_ci_si_puo_registrare_come_admin(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload(['role' => 'admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_la_password_viene_salvata_con_hash(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();

        $stored = User::first()->password;
        $this->assertNotSame('Segreta-12345', $stored);
        $this->assertTrue(password_verify('Segreta-12345', $stored));
    }

    public function test_email_e_nome_utente_devono_essere_univoci_senza_distinguere_le_maiuscole(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();

        $this->postJson('/api/auth/register', $this->registrationPayload(['email' => 'MARIO@example.com', 'username' => 'altro.nome']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/register', $this->registrationPayload(['email' => 'altro@example.com', 'username' => 'MARIO.ROSSI']))
            ->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_l_email_viene_salvata_in_minuscolo(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload(['email' => '  Mario@Example.COM ']))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'mario@example.com']);
    }

    public function test_le_password_deboli_vengono_rifiutate(): void
    {
        foreach (['Corta1', 'senzanumeri-lunga', '12345678901234'] as $weak) {
            $this->postJson('/api/auth/register', $this->registrationPayload(['password' => $weak, 'password_confirmation' => $weak]))
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }

    public function test_la_conferma_password_deve_coincidere(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload(['password_confirmation' => 'Diversa-12345']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_senza_accettare_termini_e_privacy_non_si_completa_la_registrazione(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload(['accept_terms' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
    }

    public function test_il_link_di_conferma_attiva_l_email(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();

        $this->get($this->lastVerificationUrl())->assertOk()->assertSee('Email confermata');

        $this->assertNotNull(User::first()->email_verified_at);
    }

    public function test_un_link_manomesso_viene_rifiutato(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();

        $this->get($this->lastVerificationUrl().'x')->assertForbidden();

        $this->assertNull(User::first()->email_verified_at);
    }

    public function test_il_link_di_un_altro_utente_non_conferma_questo_account(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();
        $this->postJson('/api/auth/register', $this->registrationPayload(['email' => 'luigi@example.com', 'username' => 'luigi']))->assertCreated();

        // Link firmato correttamente, ma con l'hash dell'email sbagliata
        $url = \URL::temporarySignedRoute('auth.verify-email', now()->addHour(), ['id' => User::where('email', 'mario@example.com')->value('id'), 'hash' => sha1('luigi@example.com')]);
        $this->get($url)->assertForbidden();

        $this->assertNull(User::where('email', 'mario@example.com')->value('email_verified_at'));
    }

    public function test_un_link_scaduto_viene_rifiutato(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();
        $url = $this->lastVerificationUrl();

        $this->travel(25)->hours();

        $this->get($url)->assertForbidden();
        $this->assertNull(User::first()->email_verified_at);
    }

    public function test_reinviare_la_conferma_risponde_sempre_allo_stesso_modo(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();
        $verified = User::factory()->create(['email' => 'gia@example.com']);
        Mail::fake();

        $this->postJson('/api/auth/verify-email/resend', ['email' => 'mario@example.com'])->assertStatus(202);
        Mail::assertSent(VerifyEmailMail::class, 1);

        $this->postJson('/api/auth/verify-email/resend', ['email' => 'sconosciuto@example.com'])->assertStatus(202);
        $this->postJson('/api/auth/verify-email/resend', ['email' => $verified->email])->assertStatus(202);
        Mail::assertSent(VerifyEmailMail::class, 1);
    }
}

<?php

namespace Tests\Concerns;

use App\Mail\LoginCodeMail;
use App\Mail\PasswordResetCodeMail;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

trait AuthHelpers
{
    protected function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'role' => 'customer',
            'username' => 'mario.rossi',
            'email' => 'mario@example.com',
            'password' => 'Segreta-12345',
            'password_confirmation' => 'Segreta-12345',
            'accept_terms' => true,
        ], $overrides);
    }

    /** Il codice 2FA dell'ultima email di accesso inviata. */
    protected function lastLoginCode(): string
    {
        return Mail::sent(LoginCodeMail::class)->last()->code;
    }

    protected function lastResetCode(): string
    {
        return Mail::sent(PasswordResetCodeMail::class)->last()->code;
    }

    protected function lastVerificationUrl(): string
    {
        return Mail::sent(VerifyEmailMail::class)->last()->url;
    }

    /**
     * Esegue l'intero accesso (password + codice) e restituisce la risposta con i token.
     *
     * @return array<string, mixed>
     */
    protected function loginWithCode(User $user, string $password = 'password'): array
    {
        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()
            ->json('challenge_id');

        $response = $this->postJson('/api/auth/login/verify', [
            'challenge_id' => $challenge,
            'code' => $this->lastLoginCode(),
            'device' => 'test',
        ])->assertOk();

        $this->forgetAuthenticatedUser();

        return $response->json();
    }

    /** Il guard di Laravel ricorda l'utente tra le richieste dello stesso test: lo azzeriamo. */
    protected function forgetAuthenticatedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function bearer(string $token): static
    {
        $this->forgetAuthenticatedUser();

        return $this->withToken($token);
    }
}

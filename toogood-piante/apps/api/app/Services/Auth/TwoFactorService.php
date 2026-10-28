<?php

namespace App\Services\Auth;

use App\Mail\LoginCodeMail;
use App\Models\LoginChallenge;
use App\Models\User;
use App\Support\ApiError;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Seconda verifica dell'accesso: codice a 6 cifre inviato via email, valido 10 minuti,
 * al massimo 5 tentativi, utilizzabile una sola volta.
 */
final class TwoFactorService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function start(User $user): LoginChallenge
    {
        // Un nuovo accesso rende inutilizzabili i codici precedenti
        LoginChallenge::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = LoginChallenge::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        // Invio immediato (non in coda): il codice non deve restare salvato nella tabella dei job
        Mail::to($user->email)->send(new LoginCodeMail($user, $code));

        return $challenge;
    }

    public function verify(string $publicId, string $code): User
    {
        $challenge = LoginChallenge::where('public_id', $publicId)->first();

        if (! $challenge || $challenge->used_at !== null || $challenge->expires_at->isPast()) {
            ApiError::throw('CODE_EXPIRED', 'Il codice non è più valido. Accedi di nuovo per riceverne uno nuovo.', 401);
        }

        // Conta il tentativo in modo atomico: oltre il limite il codice si blocca
        $counted = LoginChallenge::where('id', $challenge->id)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($counted === 0) {
            LoginChallenge::where('id', $challenge->id)->update(['used_at' => now()]);
            ApiError::throw('TOO_MANY_ATTEMPTS', 'Troppi tentativi. Accedi di nuovo per ricevere un nuovo codice.', 429);
        }

        if (! hash_equals($challenge->code_hash, $this->hash($code))) {
            ApiError::throw('INVALID_CODE', 'Codice non corretto.', 401);
        }

        // Il codice vale una sola volta, anche con richieste simultanee
        $consumed = LoginChallenge::where('id', $challenge->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($consumed === 0) {
            ApiError::throw('CODE_EXPIRED', 'Il codice è già stato usato.', 401);
        }

        return User::findOrFail($challenge->user_id);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}

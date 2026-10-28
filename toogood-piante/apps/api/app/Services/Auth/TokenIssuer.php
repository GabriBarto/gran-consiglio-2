<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Support\ApiError;
use Illuminate\Support\Str;

/**
 * Emette e ruota i token: un access token breve (Sanctum) e un refresh token monouso.
 * Se un refresh token già usato viene presentato di nuovo, si revocano tutte le sessioni dell'utente.
 */
final class TokenIssuer
{
    public const ACCESS_MINUTES = 15;

    public const REFRESH_DAYS = 30;

    /** @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int} */
    public function issue(User $user, ?string $device = null): array
    {
        $access = $user->createToken('access', ['*'], now()->addMinutes(self::ACCESS_MINUTES))->plainTextToken;

        return [
            'access_token' => $access,
            'refresh_token' => $this->issueRefresh($user, $device),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_MINUTES * 60,
        ];
    }

    public function issueRefresh(User $user, ?string $device = null): string
    {
        $plain = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'device' => $device,
            'expires_at' => now()->addDays(self::REFRESH_DAYS),
        ]);

        return $plain;
    }

    /** @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int} */
    public function rotate(string $plain, ?string $device = null): array
    {
        $token = RefreshToken::where('token_hash', hash('sha256', $plain))->first();

        if (! $token) {
            ApiError::throw('INVALID_REFRESH_TOKEN', 'Sessione non valida. Accedi di nuovo.', 401);
        }

        if ($token->revoked_at !== null) {
            // Già sostituito da un refresh: se torna in uso potrebbe essere stato copiato. Chiudiamo tutto.
            // Revocato per logout o cambio password: rifiutiamo soltanto, le altre sessioni restano.
            if ($token->rotated_at !== null) {
                $this->revokeAll($token->user);
                ApiError::throw('REFRESH_TOKEN_REUSED', 'Sessione non valida. Accedi di nuovo.', 401);
            }
            ApiError::throw('INVALID_REFRESH_TOKEN', 'Sessione non valida. Accedi di nuovo.', 401);
        }

        if ($token->expires_at->isPast()) {
            ApiError::throw('INVALID_REFRESH_TOKEN', 'Sessione scaduta. Accedi di nuovo.', 401);
        }

        $user = $token->user;
        if ($user->status !== User::STATUS_ACTIVE) {
            ApiError::throw('ACCOUNT_NOT_ACTIVE', 'Account non attivo.', 403);
        }

        $revoked = RefreshToken::where('id', $token->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'rotated_at' => now()]);
        if ($revoked === 0) {
            $this->revokeAll($user);
            ApiError::throw('REFRESH_TOKEN_REUSED', 'Sessione non valida. Accedi di nuovo.', 401);
        }

        return $this->issue($user, $device ?? $token->device);
    }

    public function revokeRefresh(User $user, string $plain): void
    {
        RefreshToken::where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** Chiude tutte le sessioni dell'utente, tranne (se indicato) l'access token in uso. */
    public function revokeAll(User $user, ?int $exceptAccessTokenId = null): void
    {
        RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $user->tokens()
            ->when($exceptAccessTokenId, fn ($query) => $query->where('id', '!=', $exceptAccessTokenId))
            ->delete();
    }
}

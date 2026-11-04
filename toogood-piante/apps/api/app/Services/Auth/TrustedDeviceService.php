<?php

namespace App\Services\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Ricorda questo dispositivo": dopo un accesso con codice l'app riceve un token casuale e lo
 * presenta ai login successivi, che così non richiedono un nuovo codice.
 *
 * Il token vale 90 giorni dall'ultimo uso. Si annulla con il cambio o il reset della password e
 * se viene rilevato il riuso di un refresh token. L'admin non ne ha mai: per lui il codice è sempre richiesto.
 */
final class TrustedDeviceService
{
    public const DAYS = 90;

    public function allowedFor(User $user): bool
    {
        return $user->role !== User::ROLE_ADMIN;
    }

    /** Registra il dispositivo e restituisce il token in chiaro (nel database resta solo l'hash). */
    public function remember(User $user, ?string $device = null): string
    {
        $plain = Str::random(64);

        TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'device' => $device,
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::DAYS),
        ]);

        return $plain;
    }

    /** Vero se il token è di questo utente, non scaduto e non revocato; in tal caso ne prolunga la validità. */
    public function check(User $user, ?string $plain): bool
    {
        if (! $plain || ! $this->allowedFor($user)) {
            return false;
        }

        $device = TrustedDevice::where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $device) {
            return false;
        }

        $device->forceFill(['last_used_at' => now(), 'expires_at' => now()->addDays(self::DAYS)])->save();

        return true;
    }

    public function revokeAll(User $user): void
    {
        TrustedDevice::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}

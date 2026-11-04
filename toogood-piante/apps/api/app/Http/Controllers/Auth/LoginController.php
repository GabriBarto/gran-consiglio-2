<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Services\Auth\TrustedDeviceService;
use App\Services\Auth\TwoFactorService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /**
     * Primo passo: email e password. Se corretti, invia il codice 2FA per email,
     * salvo che l'app presenti il token di un dispositivo già verificato: in quel caso si entra subito.
     */
    public function login(Request $request, TwoFactorService $twoFactor, TrustedDeviceService $devices, TokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_token' => ['nullable', 'string', 'max:128'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::where('email', strtolower(trim($data['email'])))->first();

        // Se l'utente non esiste si calcola comunque un hash, per non rivelarlo dal tempo di risposta
        $passwordOk = $user
            ? Hash::check($data['password'], $user->password)
            : Hash::check($data['password'], Hash::make('password-fittizia'));

        if (! $user || ! $passwordOk || $user->status === User::STATUS_DELETED) {
            ApiError::throw('INVALID_CREDENTIALS', 'Email o password non corrette.', 401);
        }

        if ($user->status === User::STATUS_SUSPENDED) {
            ApiError::throw('ACCOUNT_SUSPENDED', 'Il tuo account è sospeso.', 403);
        }

        if ($user->email_verified_at === null) {
            ApiError::throw('EMAIL_NOT_VERIFIED', 'Conferma prima il tuo indirizzo email dal link che ti abbiamo inviato.', 403);
        }

        // Dispositivo già verificato: niente nuovo codice
        if ($devices->check($user, $data['device_token'] ?? null)) {
            return response()->json($tokens->issue($user, $data['device'] ?? null) + [
                'requires_code' => false,
                'user' => new UserResource($user),
            ]);
        }

        $challenge = $twoFactor->start($user);

        return response()->json([
            'requires_code' => true,
            'message' => 'Ti abbiamo inviato un codice di accesso via email.',
            'challenge_id' => $challenge->public_id,
            'expires_in' => TwoFactorService::TTL_MINUTES * 60,
        ]);
    }

    /** Secondo passo: codice ricevuto per email. Se corretto, emette i token (e, se richiesto, ricorda il dispositivo). */
    public function verifyCode(Request $request, TwoFactorService $twoFactor, TokenIssuer $tokens, TrustedDeviceService $devices): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
            'device' => ['nullable', 'string', 'max:120'],
            'remember_device' => ['nullable', 'boolean'],
        ]);

        $user = $twoFactor->verify($data['challenge_id'], $data['code']);

        if ($user->status !== User::STATUS_ACTIVE) {
            ApiError::throw('ACCOUNT_NOT_ACTIVE', 'Il tuo account non è attivo.', 403);
        }

        $response = $tokens->issue($user, $data['device'] ?? null) + ['user' => new UserResource($user)];

        if (($data['remember_device'] ?? false) && $devices->allowedFor($user)) {
            $response['device_token'] = $devices->remember($user, $data['device'] ?? null);
        }

        return response()->json($response);
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Services\Auth\TokenIssuer;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    private const CODE_MINUTES = 30;

    private const MAX_ATTEMPTS = 5;

    /** Invia un codice per email. Risposta sempre uguale: non rivela se l'indirizzo è registrato. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower(trim($data['email']));

        $user = User::where('email', $email)->where('status', User::STATUS_ACTIVE)->first();
        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => $this->hash($code), 'attempts' => 0, 'created_at' => now()],
            );

            Mail::to($user->email)->send(new PasswordResetCodeMail($user, $code));
        }

        return response()->json(['message' => 'Se l\'indirizzo è registrato, riceverai un codice per reimpostare la password.'], 202);
    }

    public function reset(Request $request, TokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $email = strtolower(trim($data['email']));

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        $expired = $row === null
            || now()->subMinutes(self::CODE_MINUTES)->greaterThan($row->created_at)
            || $row->attempts >= self::MAX_ATTEMPTS;

        if ($expired) {
            ApiError::throw('INVALID_RESET_CODE', 'Codice non valido o scaduto. Richiedine uno nuovo.', 422);
        }

        if (! hash_equals($row->token, $this->hash($data['code']))) {
            DB::table('password_reset_tokens')->where('email', $email)->increment('attempts');
            ApiError::throw('INVALID_RESET_CODE', 'Codice non valido o scaduto. Richiedine uno nuovo.', 422);
        }

        $user = User::where('email', $email)->where('status', User::STATUS_ACTIVE)->firstOrFail();

        DB::transaction(function () use ($user, $data, $email) {
            $user->password = $data['password'];
            $user->must_change_password = false;
            // Il codice è arrivato alla casella email: dimostra che l'indirizzo è davvero suo
            $user->email_verified_at ??= now();
            $user->save();

            DB::table('password_reset_tokens')->where('email', $email)->delete();
        });

        // Dopo il reset tutte le sessioni esistenti si chiudono
        $tokens->revokeAll($user);

        return response()->json(['message' => 'Password aggiornata. Ora puoi accedere.']);
    }

    /** Cambio password da utente autenticato (obbligatorio al primo accesso dell'admin). */
    public function change(Request $request, TokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            ApiError::throw('INVALID_CURRENT_PASSWORD', 'La password attuale non è corretta.', 422);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->save();

        // Si chiudono le altre sessioni; resta quella in uso, con un nuovo refresh token
        $tokens->revokeAll($user, $user->currentAccessToken()?->id);

        return response()->json([
            'message' => 'Password aggiornata.',
            'refresh_token' => $tokens->issueRefresh($user),
        ]);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}

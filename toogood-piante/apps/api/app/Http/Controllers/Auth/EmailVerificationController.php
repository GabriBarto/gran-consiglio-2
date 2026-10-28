<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    /** Apre il link ricevuto per email. La firma e la scadenza sono controllate dal middleware "signed". */
    public function verify(Request $request, EmailVerificationService $verification, int $id, string $hash): Response|JsonResponse
    {
        $user = User::find($id);
        $valid = $user !== null && $user->status === User::STATUS_ACTIVE && $verification->matches($user, $hash);

        if ($valid && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if ($request->expectsJson()) {
            return $valid
                ? response()->json(['message' => 'Email confermata.'])
                : response()->json(['code' => 'INVALID_LINK', 'message' => 'Link non valido.'], 403);
        }

        return response()->view('auth.email-verified', ['ok' => $valid], $valid ? 200 : 403);
    }

    /** Richiede una nuova email di conferma. Risposta sempre uguale: non rivela se l'indirizzo è registrato. */
    public function resend(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', strtolower(trim($data['email'])))->first();
        if ($user && $user->status === User::STATUS_ACTIVE && $user->email_verified_at === null) {
            $verification->send($user);
        }

        return response()->json(['message' => 'Se l\'indirizzo è registrato e non ancora confermato, riceverai una nuova email.'], 202);
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function __invoke(Request $request, EmailVerificationService $verification): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'role' => ['required', Rule::in(['customer', 'seller'])],
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[\pL\pN][\pL\pN ._\'&-]*[\pL\pN]$/u', 'unique:users,username'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_terms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = new User([
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            // role e status non sono assegnabili in massa: si impostano qui, mai dall'input
            $user->role = $data['role'] === 'seller' ? User::ROLE_SELLER : User::ROLE_CUSTOMER;
            $user->status = User::STATUS_ACTIVE;
            $user->save();

            return $user;
        });

        $verification->send($user);

        return response()->json([
            'message' => 'Registrazione completata. Ti abbiamo inviato un\'email per confermare l\'indirizzo.',
            'user' => new UserResource($user),
        ], 201);
    }
}

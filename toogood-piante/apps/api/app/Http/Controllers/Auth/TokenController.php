<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TokenController extends Controller
{
    public function refresh(Request $request, TokenIssuer $tokens): JsonResponse
    {
        $data = $request->validate([
            'refresh_token' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json($tokens->rotate($data['refresh_token'], $data['device'] ?? null));
    }

    /** Esce da questo dispositivo: invalida l'access token in uso e, se indicato, il refresh token. */
    public function logout(Request $request, TokenIssuer $tokens): Response
    {
        $request->user()->currentAccessToken()?->delete();

        if (is_string($request->input('refresh_token'))) {
            $tokens->revokeRefresh($request->user(), $request->input('refresh_token'));
        }

        return response()->noContent();
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocca gli account sospesi o cancellati anche se hanno ancora un token valido. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== User::STATUS_ACTIVE) {
            ApiError::throw('ACCOUNT_NOT_ACTIVE', 'Il tuo account non è attivo.', 403);
        }

        return $next($request);
    }
}

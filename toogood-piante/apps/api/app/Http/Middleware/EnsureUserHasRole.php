<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Limita una rotta a uno o più ruoli: ->middleware('role:ADMIN') */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role, $roles, true)) {
            ApiError::throw('FORBIDDEN_ROLE', 'Non hai i permessi per questa operazione.', 403);
        }

        return $next($request);
    }
}

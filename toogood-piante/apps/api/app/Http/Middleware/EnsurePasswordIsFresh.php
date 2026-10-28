<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** L'admin con password iniziale può solo cambiarla: tutto il resto resta bloccato. */
class EnsurePasswordIsFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            ApiError::throw('PASSWORD_CHANGE_REQUIRED', 'Devi cambiare la password prima di continuare.', 403);
        }

        return $next($request);
    }
}

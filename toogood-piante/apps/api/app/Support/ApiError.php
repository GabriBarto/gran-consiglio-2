<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Errore dell'API con un codice leggibile dalle app (es. INVALID_CREDENTIALS)
 * e un messaggio in italiano.
 */
final class ApiError
{
    public static function throw(string $code, string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], $status));
    }
}

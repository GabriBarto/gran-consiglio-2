<?php

namespace App\Services\Seller;

use App\Models\Seller;
use App\Support\ApiError;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Verifica la partita IVA presso il servizio VIES (simulato in sviluppo). */
class VatChecker
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * Restituisce VALID oppure UNAVAILABLE (servizio giù). Una P.IVA inesistente è rifiutata subito.
     * Con il servizio giù non si blocca il venditore: l'esito resta "non verificato" e lo vede l'admin.
     */
    public function check(string $vat): string
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->get(rtrim(config('toogood.services.vies_url'), '/').'/'.$vat);
        } catch (Throwable) {
            return Seller::VAT_UNAVAILABLE;
        }

        if ($response->serverError()) {
            return Seller::VAT_UNAVAILABLE;
        }

        if (! $response->successful() || ! ($response->json('valid') ?? false)) {
            ApiError::throw('VAT_INVALID', 'La partita IVA non risulta valida.', 422);
        }

        return Seller::VAT_VALID;
    }
}

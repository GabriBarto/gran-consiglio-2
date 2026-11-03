<?php

namespace App\Services\Seller;

use App\Support\ApiError;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Trasforma un indirizzo in coordinate per la ricerca per distanza (servizio simulato in sviluppo). */
class Geocoder
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * @return array{lat: float, lng: float, city: string}
     */
    public function locate(string $address): array
    {
        try {
            $data = Http::timeout(self::TIMEOUT_SECONDS)
                ->get(config('toogood.services.geocoding_url'), ['address' => $address])
                ->throw()
                ->json();
        } catch (Throwable) {
            // Servizio lento o non raggiungibile: non è colpa dell'utente, può riprovare
            ApiError::throw('GEOCODING_UNAVAILABLE', 'Non riusciamo a verificare l\'indirizzo in questo momento. Riprova tra poco.', 503);
        }

        if (! ($data['found'] ?? false)) {
            ApiError::throw('ADDRESS_NOT_FOUND', 'Indirizzo non trovato. Controlla via, numero e città.', 422);
        }

        return ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'city' => (string) $data['city']];
    }
}

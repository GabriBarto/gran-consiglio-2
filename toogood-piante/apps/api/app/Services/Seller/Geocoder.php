<?php

namespace App\Services\Seller;

use App\Support\ApiError;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Trasforma un indirizzo in coordinate per la ricerca per distanza.
 *
 * Due "driver" (config toogood.services.geocoding_driver):
 *  - nominatim: OpenStreetMap, indirizzi reali di tutta Italia (richiede internet);
 *  - simulated: il servizio finto di WireMock, usato dai test.
 */
class Geocoder
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * @return array{lat: float, lng: float, city: string}
     */
    public function locate(string $address): array
    {
        try {
            $result = config('toogood.services.geocoding_driver') === 'simulated'
                ? $this->simulated($address)
                : $this->nominatim($address);
        } catch (Throwable) {
            // Servizio lento o non raggiungibile: non è colpa dell'utente, può riprovare
            ApiError::throw('GEOCODING_UNAVAILABLE', 'Non riusciamo a verificare l\'indirizzo in questo momento. Riprova tra poco.', 503);
        }

        if ($result === null) {
            ApiError::throw('ADDRESS_NOT_FOUND', 'Indirizzo non trovato. Controlla via, numero e città.', 422);
        }

        return $result;
    }

    /** @return array{lat: float, lng: float, city: string}|null */
    private function simulated(string $address): ?array
    {
        $data = Http::timeout(self::TIMEOUT_SECONDS)
            ->get(config('toogood.services.geocoding_url'), ['address' => $address])
            ->throw()
            ->json();

        if (! ($data['found'] ?? false)) {
            return null;
        }

        return ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'city' => (string) $data['city']];
    }

    /**
     * Nominatim (OpenStreetMap), limitato all'Italia. Accetta anche solo una città o un paese.
     * Le regole d'uso chiedono uno User-Agent che identifichi l'app e al massimo una richiesta al secondo:
     * l'indirizzo si cerca solo quando il venditore lo salva o lo cambia, quindi siamo ben dentro il limite.
     *
     * @return array{lat: float, lng: float, city: string}|null
     */
    private function nominatim(string $address): ?array
    {
        $rows = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['User-Agent' => config('toogood.services.geocoding_user_agent')])
            ->get(rtrim((string) config('toogood.services.nominatim_url'), '/').'/search', [
                'q' => $address,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'countrycodes' => 'it',
                'accept-language' => 'it',
                'limit' => 1,
            ])
            ->throw()
            ->json();

        $hit = $rows[0] ?? null;
        if (! $hit || ! isset($hit['lat'], $hit['lon'])) {
            return null;
        }

        $a = $hit['address'] ?? [];
        $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['hamlet'] ?? $a['county'] ?? null;
        if ($city === null) {
            return null;
        }

        return ['lat' => round((float) $hit['lat'], 6), 'lng' => round((float) $hit['lon'], 6), 'city' => (string) $city];
    }
}

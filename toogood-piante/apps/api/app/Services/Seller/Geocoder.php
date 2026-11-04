<?php

namespace App\Services\Seller;

use App\Support\ApiError;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    /** Quanti risultati chiedere a Nominatim: serve scegliere quello nella città giusta, non il primo. */
    private const CANDIDATES = 8;

    /** Parole che nei nomi dei comuni non distinguono ("Reggio nell'Emilia" = "Reggio Emilia"). */
    private const STOPWORDS = ['di', 'del', 'della', 'dello', 'dei', 'degli', 'delle', 'dell', 'nel', 'nella', 'nello', 'nell', 'sul', 'sulla', 'in', 'a', 'al', 'alla', 'd', 'l'];

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

        // Esiste un indirizzo simile, ma in un altro comune: non lo si accetta in silenzio
        if (isset($result['other_city'])) {
            ApiError::throw(
                'ADDRESS_CITY_MISMATCH',
                "Non troviamo questo indirizzo a {$result['typed_city']}: l'indirizzo più simile è a {$result['other_city']}. Controlla via, numero e città.",
                422,
            );
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
     *
     * Nominatim restituisce il miglior numero civico che trova nei dintorni, anche in un altro comune
     * ("Via Garibaldi 12, Bologna" → Sasso Marconi). Per questo si chiedono più risultati e si sceglie il
     * primo il cui comune coincide con la città scritta dopo l'ultima virgola. Se nessuno coincide si
     * segnala l'errore invece di salvare coordinate di un altro posto.
     *
     * Le regole d'uso di Nominatim chiedono uno User-Agent che identifichi l'app e al massimo una richiesta
     * al secondo: si cerca solo quando il venditore salva o cambia l'indirizzo, quindi siamo ben dentro il limite.
     *
     * @return array{lat: float, lng: float, city: string}|array{other_city: string, typed_city: string}|null
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
                'limit' => self::CANDIDATES,
            ])
            ->throw()
            ->json();

        $candidates = array_values(array_filter($rows ?? [], fn ($r) => isset($r['lat'], $r['lon']) && $this->commune($r['address'] ?? []) !== null));
        if ($candidates === []) {
            return null;
        }

        $typedCity = $this->typedCity($address);
        $hit = $candidates[0];

        if ($typedCity !== null) {
            $hit = null;
            foreach ($candidates as $candidate) {
                if ($this->matches($typedCity, $candidate['address'] ?? [])) {
                    $hit = $candidate;
                    break;
                }
            }

            if ($hit === null) {
                return ['other_city' => $this->commune($candidates[0]['address']), 'typed_city' => $typedCity];
            }
        }

        return [
            'lat' => round((float) $hit['lat'], 6),
            'lng' => round((float) $hit['lon'], 6),
            'city' => $this->commune($hit['address']),
        ];
    }

    /**
     * La città scritta dall'utente: l'ultimo pezzo dopo una virgola, senza CAP, sigla di provincia o "Italia".
     * Con un solo pezzo ("Pesaro", "Corniglio") non c'è nulla da confrontare: ritorna null.
     */
    private function typedCity(string $address): ?string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== ''));

        if ($parts !== [] && in_array(Str::lower(end($parts)), ['italia', 'italy'], true)) {
            array_pop($parts);
        }
        if (count($parts) < 2) {
            return null;
        }

        $city = end($parts);
        $city = preg_replace('/\b\d{5}\b/', '', $city);               // CAP
        $city = preg_replace('/\(\s*[A-Za-z]{2}\s*\)/', '', $city);   // (BO)
        $city = preg_replace('/\s+[A-Z]{2}$/', '', trim($city));       // BO
        $city = trim($city, " \t-");

        return $city === '' ? null : $city;
    }

    /** Il comune del risultato: città, paese o villaggio; solo se mancano, il campo "municipality" o la frazione. */
    private function commune(array $address): ?string
    {
        foreach (['city', 'town', 'village', 'municipality', 'hamlet', 'county'] as $key) {
            if (! empty($address[$key])) {
                return (string) $address[$key];
            }
        }

        return null;
    }

    /**
     * La città scritta è il comune del risultato, o una sua frazione o quartiere?
     *
     * Serve **uguaglianza** del nome (ignorando accenti, maiuscole e articoli), non "contiene": in OpenStreetMap
     * compaiono nomi come "Zona Omogenea Milano Nord" (unione di comuni) o "Milano Oltre" (quartiere di Segrate)
     * che contengono "Milano" senza essere Milano. Per lo stesso motivo il campo "municipality" conta solo
     * quando manca il nome di città/paese vero, e la provincia (county) non conta mai.
     */
    private function matches(string $typedCity, array $address): bool
    {
        $typed = $this->tokens($typedCity);
        if ($typed === []) {
            return false;
        }

        $names = [$this->commune($address)];
        foreach (['hamlet', 'suburb', 'city_district', 'quarter', 'neighbourhood'] as $key) {
            $names[] = $address[$key] ?? null;
        }

        foreach ($names as $name) {
            if ($name !== null && $this->tokens((string) $name) === $typed) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> parole minuscole, senza accenti, punteggiatura e articoli */
    private function tokens(string $name): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff($words, self::STOPWORDS));
    }
}

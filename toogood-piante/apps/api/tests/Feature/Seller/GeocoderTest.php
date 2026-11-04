<?php

namespace Tests\Feature\Seller;

use App\Services\Seller\Geocoder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Il driver reale (Nominatim) con risposte finte: nessuna chiamata a internet. */
class GeocoderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'toogood.services.geocoding_driver' => 'nominatim',
            'toogood.services.nominatim_url' => 'http://nominatim.test',
        ]);
    }

    public function test_trova_una_citta_qualsiasi_d_italia(): void
    {
        Http::fake(['nominatim.test/*' => Http::response([[
            'lat' => '43.9098', 'lon' => '12.9131', 'address' => ['city' => 'Pesaro', 'country_code' => 'it'],
        ]])]);

        $this->assertSame(
            ['lat' => 43.9098, 'lng' => 12.9131, 'city' => 'Pesaro'],
            app(Geocoder::class)->locate('Via Rossini 10, Pesaro'),
        );

        Http::assertSent(fn (Request $r) => $r['countrycodes'] === 'it'
            && $r['q'] === 'Via Rossini 10, Pesaro'
            && str_contains($r->header('User-Agent')[0], 'TooGoodPiante'));
    }

    public function test_un_paese_piccolo_usa_il_nome_del_villaggio(): void
    {
        Http::fake(['nominatim.test/*' => Http::response([[
            'lat' => '44.5', 'lon' => '10.1', 'address' => ['village' => 'Corniglio'],
        ]])]);

        $this->assertSame('Corniglio', app(Geocoder::class)->locate('Corniglio')['city']);
    }

    /** Risposta finta di Nominatim con i comuni indicati, nell'ordine dato. */
    private function fakeCities(array ...$rows): void
    {
        // Un secondo Http::fake non sostituisce il primo: si riparte da una factory pulita a ogni chiamata
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['nominatim.test/*' => Http::response(array_map(
            fn (array $address) => ['lat' => '44.0', 'lon' => '11.0', 'address' => $address],
            $rows,
        ))]);
    }

    public function test_un_indirizzo_in_un_altro_comune_non_viene_accettato(): void
    {
        // Il caso reale: "Via Garibaldi 12, Bologna" → Nominatim trova solo il civico di Sasso Marconi
        $this->fakeCities(['town' => 'Sasso Marconi', 'road' => 'Via Garibaldi', 'house_number' => '12']);

        $e = $this->assertErrore(422, 'ADDRESS_CITY_MISMATCH', 'Via Garibaldi 12, Bologna');
        $this->assertStringContainsString('Bologna', $e['message']);
        $this->assertStringContainsString('Sasso Marconi', $e['message']);
    }

    public function test_sceglie_il_risultato_nella_citta_scritta_anche_se_non_e_il_primo(): void
    {
        $this->fakeCities(['town' => 'Budrio'], ['city' => 'Bologna'], ['city' => 'Bologna']);

        $this->assertSame('Bologna', app(Geocoder::class)->locate('Via Rizzoli 5, Bologna')['city']);
    }

    public function test_le_varianti_del_nome_del_comune_sono_accettate(): void
    {
        $this->fakeCities(['city' => "Reggio nell'Emilia"]);
        $this->assertSame("Reggio nell'Emilia", app(Geocoder::class)->locate('Via Roma 1, Reggio Emilia')['city']);

        $this->fakeCities(['city' => 'Forlì']);
        $this->assertSame('Forlì', app(Geocoder::class)->locate('Corso Garibaldi 3, FORLI')['city']);

        $this->fakeCities(['city' => 'Sant\'Angelo Lodigiano']);
        $this->assertSame('Sant\'Angelo Lodigiano', app(Geocoder::class)->locate('Via Dante 2, Sant Angelo Lodigiano')['city']);
    }

    public function test_cap_provincia_e_italia_dopo_la_citta_non_disturbano(): void
    {
        $this->fakeCities(['city' => 'Bologna']);

        foreach (['Via Rizzoli 5, 40125 Bologna', 'Via Rizzoli 5, Bologna BO', 'Via Rizzoli 5, Bologna (BO)', 'Via Rizzoli 5, 40125 Bologna BO, Italia'] as $address) {
            $this->assertSame('Bologna', app(Geocoder::class)->locate($address)['city'], $address);
        }
    }

    public function test_una_frazione_vale_come_il_suo_comune_che_resta_la_citta_salvata(): void
    {
        $this->fakeCities(['town' => 'Sasso Marconi', 'hamlet' => 'Pontecchio Marconi']);

        $this->assertSame('Sasso Marconi', app(Geocoder::class)->locate('Via Porrettana 10, Pontecchio Marconi')['city']);
    }

    public function test_un_nome_che_contiene_solo_la_citta_non_basta(): void
    {
        // Dati reali di "Via Roma 1, Milano": nessun civico a Milano, ma risultati che nominano Milano
        $this->fakeCities(
            ['town' => 'Bresso', 'municipality' => 'Zona Omogenea Milano Nord', 'county' => 'Milano'],
            ['town' => 'Segrate', 'quarter' => 'Milano Oltre', 'municipality' => 'Rodano', 'county' => 'Milano'],
        );

        $e = $this->assertErrore(422, 'ADDRESS_CITY_MISMATCH', 'Via Roma 1, Milano');
        $this->assertStringContainsString('Bresso', $e['message']);
    }

    public function test_la_provincia_non_vale_come_citta(): void
    {
        $this->fakeCities(['town' => 'Sasso Marconi', 'county' => 'Bologna']);

        $this->assertErrore(422, 'ADDRESS_CITY_MISMATCH', 'Via Garibaldi 12, Bologna');
    }

    public function test_senza_virgole_si_cerca_solo_la_citta_senza_confronti(): void
    {
        $this->fakeCities(['city' => 'Pesaro']);

        $this->assertSame('Pesaro', app(Geocoder::class)->locate('Pesaro')['city']);
        $this->assertSame('Pesaro', app(Geocoder::class)->locate('Pesaro, Italia')['city']);
    }

    public function test_indirizzo_sconosciuto_da_422(): void
    {
        Http::fake(['nominatim.test/*' => Http::response([])]);

        $this->assertErrore(422, 'ADDRESS_NOT_FOUND', 'asdfghjkl 99');
    }

    public function test_servizio_giu_da_503(): void
    {
        Http::fake(['nominatim.test/*' => Http::response('errore', 500)]);

        $this->assertErrore(503, 'GEOCODING_UNAVAILABLE', 'Via Roma 1, Milano');
    }

    /** @return array<string, mixed> il corpo JSON dell'errore */
    private function assertErrore(int $status, string $code, string $address): array
    {
        try {
            app(Geocoder::class)->locate($address);
            $this->fail('Doveva fallire');
        } catch (HttpResponseException $e) {
            $this->assertSame($status, $e->getResponse()->getStatusCode());
            $body = $e->getResponse()->getData(true);
            $this->assertSame($code, $body['code']);

            return $body;
        }
    }
}

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

    private function assertErrore(int $status, string $code, string $address): void
    {
        try {
            app(Geocoder::class)->locate($address);
            $this->fail('Doveva fallire');
        } catch (HttpResponseException $e) {
            $this->assertSame($status, $e->getResponse()->getStatusCode());
            $this->assertSame($code, $e->getResponse()->getData(true)['code']);
        }
    }
}

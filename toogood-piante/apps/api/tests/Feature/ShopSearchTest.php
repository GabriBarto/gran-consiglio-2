<?php

namespace Tests\Feature;

use App\Models\BoxOffer;
use App\Models\Seller;
use App\Models\User;
use App\Support\Clock;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SellerHelpers;
use Tests\TestCase;

class ShopSearchTest extends TestCase
{
    use RefreshDatabase, SellerHelpers;

    // Dove si trova chi cerca: il centro di Bologna
    private const BOLOGNA = ['lat' => 44.4938, 'lng' => 11.3426];

    private int $vat = 10000000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        // Lunedì 15 giugno 2026, 10:00 a Roma (08:00 UTC): la fascia di ritiro dei negozi finisce alle 19:00, ordini fino alle 17:00
        Carbon::setTestNow('2026-06-15 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un negozio che può vendere (approvato, conto collegato), con i dati indicati. */
    private function shop(string $name, string $city, float $lat, float $lng, array $extra = []): Seller
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_APPROVED, vat: 'IT'.++$this->vat);
        $seller->forceFill([
            'business_name' => $name, 'city' => $city, 'latitude' => $lat, 'longitude' => $lng,
            'stripe_account_id' => 'acct_test', 'stripe_onboarded_at' => now(),
        ] + $extra)->save();

        return $seller;
    }

    private function box(Seller $seller, array $overrides = []): BoxOffer
    {
        $box = new BoxOffer;
        $box->seller_id = $seller->id;
        $box->offer_date = $overrides['offer_date'] ?? app(Clock::class)->todayLocal()->toDateString();
        $box->box_type_id = DB::table('box_types')->where('code', 'MEDIUM')->value('id');
        $box->category_id = DB::table('categories')->where('code', 'SUCCULENTS')->value('id');
        $box->setPlantAttributes($overrides['attributes'] ?? ['INDOOR', 'TOXIC_PETS']);
        $box->description = $overrides['description'] ?? 'Tre piante grasse miste';
        $box->list_value_cents = $overrides['list_value_cents'] ?? 2000;
        $box->discount_pct = 65;
        $box->price_cents = $overrides['price_cents'] ?? 700;
        $box->fee_cents = 150;
        $box->stock_total = $overrides['stock_total'] ?? 5;
        $box->stock_available = $overrides['stock_available'] ?? 5;
        $box->status = $overrides['status'] ?? BoxOffer::STATUS_ACTIVE;
        $box->save();

        return $box;
    }

    /** Quattro negozi con box oggi: Bologna, Pesaro, Milano, Roma. */
    private function italy(): array
    {
        $shops = [
            'bologna' => $this->shop('Vivaio Due Torri', 'Bologna', 44.4949, 11.3426),
            'pesaro' => $this->shop('Fiori del Mare', 'Pesaro', 43.9098, 12.9131),
            'milano' => $this->shop('Verde Navigli', 'Milano', 45.4642, 9.1900),
            'roma' => $this->shop('Piante Capitali', 'Roma', 41.9028, 12.4964),
        ];
        foreach ($shops as $s) {
            $this->box($s);
        }

        return $shops;
    }

    /** @return list<string> i nomi dei negozi nell'ordine della risposta */
    private function names(array $query = []): array
    {
        return array_column($this->getJson('/api/shops?'.http_build_query($query))->assertOk()->json('data'), 'business_name');
    }

    public function test_la_ricerca_e_pubblica_e_ordina_per_distanza(): void
    {
        $this->italy();

        $response = $this->getJson('/api/shops?'.http_build_query(self::BOLOGNA))->assertOk()
            ->assertJsonPath('sorted_by', 'distance')
            ->assertJsonPath('date', '2026-06-15');

        $this->assertSame(['Vivaio Due Torri', 'Fiori del Mare', 'Verde Navigli', 'Piante Capitali'], array_column($response->json('data'), 'business_name'));

        // Le distanze si confrontano con una formula di Haversine indipendente da MySQL (scarto ammesso: 0,5%)
        $distances = array_column($response->json('data'), 'distance_m');
        foreach ([[44.4949, 11.3426], [43.9098, 12.9131], [45.4642, 9.19], [41.9028, 12.4964]] as $i => [$lat, $lng]) {
            $expected = $this->haversine(self::BOLOGNA['lat'], self::BOLOGNA['lng'], $lat, $lng);
            $this->assertEqualsWithDelta($expected, $distances[$i], max(5, $expected * 0.005), "negozio #{$i}");
        }
        $this->assertEqualsWithDelta(141000, $distances[1], 3000); // Bologna–Pesaro in linea d'aria
    }

    /** Distanza in metri tra due punti sulla sfera terrestre. */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371000 * asin(sqrt($a));
    }

    public function test_senza_coordinate_si_ordina_per_nome_senza_distanza(): void
    {
        $this->italy();

        $this->assertSame(['Fiori del Mare', 'Piante Capitali', 'Verde Navigli', 'Vivaio Due Torri'], $this->names());
        $this->getJson('/api/shops')->assertJsonPath('sorted_by', 'name')->assertJsonPath('data.0.distance_m', null);
    }

    public function test_compaiono_solo_negozi_che_possono_vendere(): void
    {
        $visible = $this->shop('Negozio Visibile', 'Bologna', 44.5, 11.3);
        $this->box($visible);

        $pending = $this->sellerWithProfile(Seller::STATUS_PENDING, vat: 'IT55555555551');
        $noStripe = $this->sellerWithProfile(Seller::STATUS_APPROVED, vat: 'IT55555555552');
        $suspended = $this->shop('Negozio Sospeso', 'Bologna', 44.5, 11.3, ['verification_status' => Seller::STATUS_SUSPENDED]);
        $rejected = $this->shop('Negozio Rifiutato', 'Bologna', 44.5, 11.3, ['verification_status' => Seller::STATUS_REJECTED]);
        foreach ([$pending, $noStripe, $suspended, $rejected] as $s) {
            $this->box($s);
        }

        $this->assertSame(['Negozio Visibile'], $this->names(['include_empty' => 1]));
    }

    public function test_solo_negozi_con_almeno_una_box_disponibile_oggi(): void
    {
        $with = $this->shop('Con Box', 'Bologna', 44.5, 11.3);
        $this->box($with);

        $empty = $this->shop('Senza Box', 'Bologna', 44.5, 11.3);

        $soldOut = $this->shop('Tutto Esaurito', 'Bologna', 44.5, 11.3);
        $this->box($soldOut, ['stock_available' => 0]);

        $yesterday = $this->shop('Box Di Ieri', 'Bologna', 44.5, 11.3);
        $this->box($yesterday, ['offer_date' => '2026-06-14']);

        $deleted = $this->shop('Box Eliminata', 'Bologna', 44.5, 11.3);
        $this->box($deleted)->delete();

        $closed = $this->shop('Box Chiusa', 'Bologna', 44.5, 11.3);
        $this->box($closed, ['status' => BoxOffer::STATUS_CLOSED]);

        $this->assertSame(['Con Box'], $this->names());

        // Con il filtro "mostra anche gli altri" compaiono tutti
        $this->assertEqualsCanonicalizing(
            ['Con Box', 'Senza Box', 'Tutto Esaurito', 'Box Di Ieri', 'Box Eliminata', 'Box Chiusa'],
            $this->names(['include_empty' => 1]),
        );
    }

    public function test_riepilogo_di_box_disponibili_e_prezzo_minimo(): void
    {
        $shop = $this->shop('Vivaio Rossi', 'Bologna', 44.5, 11.3);
        $this->box($shop, ['price_cents' => 900, 'stock_available' => 3]);
        $this->box($shop, ['price_cents' => 450, 'stock_available' => 2]);
        $this->box($shop, ['price_cents' => 100, 'stock_available' => 0]); // esaurita: non conta

        $this->getJson('/api/shops')->assertOk()
            ->assertJsonPath('data.0.available_offers', 2)
            ->assertJsonPath('data.0.available_units', 5)
            ->assertJsonPath('data.0.min_price_cents', 450)
            ->assertJsonPath('data.0.pickup_from', '17:00')
            ->assertJsonPath('data.0.pickup_to', '19:00')
            ->assertJsonPath('data.0.rating', ['average' => null, 'count' => 0]);
    }

    public function test_il_raggio_esclude_i_negozi_lontani(): void
    {
        $this->italy();

        $this->assertSame(['Vivaio Due Torri'], $this->names(self::BOLOGNA + ['radius_km' => 10]));
        $this->assertSame(['Vivaio Due Torri', 'Fiori del Mare'], $this->names(self::BOLOGNA + ['radius_km' => 150]));
        $this->assertCount(4, $this->names(self::BOLOGNA + ['radius_km' => 500]));
    }

    public function test_si_cerca_per_citta_e_per_nome_senza_badare_a_maiuscole_e_accenti(): void
    {
        $this->shop('Giardino Romagnolo', 'Forlì', 44.2227, 12.0407);
        $this->box(Seller::where('city', 'Forlì')->first());
        $this->italy();

        $this->assertSame(['Giardino Romagnolo'], $this->names(['city' => 'forli']));
        $this->assertSame(['Giardino Romagnolo'], $this->names(['city' => 'FORLÌ']));
        $this->assertSame(['Verde Navigli'], $this->names(['city' => 'mila']));            // parziale
        $this->assertSame(['Piante Capitali'], $this->names(['q' => 'capitali']));          // nome, minuscole
        $this->assertSame(['Fiori del Mare'], $this->names(['q' => 'del mare']));
        $this->assertSame(['Fiori del Mare'], $this->names(['q' => 'mare', 'city' => 'pesaro']));
        $this->assertSame([], $this->names(['q' => 'mare', 'city' => 'roma']));            // i due filtri si sommano
    }

    public function test_i_caratteri_speciali_della_ricerca_non_sono_jolly(): void
    {
        $this->italy();

        $this->assertSame([], $this->names(['q' => '%']));
        $this->assertSame([], $this->names(['q' => '_']));
        $this->assertSame([], $this->names(['city' => '%']));
    }

    public function test_parametri_non_validi_sono_rifiutati(): void
    {
        $this->getJson('/api/shops?lat=44.5')->assertUnprocessable()->assertJsonValidationErrors('lng');
        $this->getJson('/api/shops?lng=11.3')->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->getJson('/api/shops?lat=95&lng=11')->assertUnprocessable()->assertJsonValidationErrors('lat');
        $this->getJson('/api/shops?lat=44&lng=200')->assertUnprocessable()->assertJsonValidationErrors('lng');
        $this->getJson('/api/shops?limit=500')->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->getJson('/api/shops?lat=44&lng=11&radius_km=0')->assertUnprocessable()->assertJsonValidationErrors('radius_km');
    }

    public function test_il_limite_taglia_i_risultati(): void
    {
        $this->italy();

        $this->assertCount(2, $this->names(self::BOLOGNA + ['limit' => 2]));
    }

    public function test_i_dati_riservati_del_venditore_non_escono(): void
    {
        $shop = $this->shop('Vivaio Rossi', 'Bologna', 44.5, 11.3);
        $this->box($shop);

        $list = json_encode($this->getJson('/api/shops')->assertOk()->json());
        $detail = json_encode($this->getJson("/api/shops/{$shop->id}")->assertOk()->json());

        foreach ([$list, $detail] as $body) {
            $this->assertStringNotContainsString($shop->vat_number, $body);
            $this->assertStringNotContainsString($shop->phone, $body);
            $this->assertStringNotContainsString($shop->user->email, $body);
            $this->assertStringNotContainsString('acct_test', $body);
            $this->assertStringNotContainsString('verification_status', $body);
        }
    }

    public function test_la_scheda_mostra_orari_fascia_di_ritiro_e_box_con_risparmio(): void
    {
        $shop = $this->shop('Vivaio Due Torri', 'Bologna', 44.4949, 11.3426);
        $this->box($shop, ['price_cents' => 700, 'list_value_cents' => 2000, 'stock_available' => 3, 'description' => 'Tre piante grasse']);

        $this->getJson("/api/shops/{$shop->id}?".http_build_query(self::BOLOGNA))->assertOk()
            ->assertJsonPath('shop.business_name', 'Vivaio Due Torri')
            ->assertJsonPath('shop.address', 'Via Roma 1, Milano')
            ->assertJsonPath('shop.opens_at', '08:00')
            ->assertJsonPath('shop.closes_at', '19:00')
            ->assertJsonPath('shop.pickup_from', '17:00')
            ->assertJsonPath('shop.pickup_to', '19:00')
            ->assertJsonPath('shop.orders_open', true)
            ->assertJsonPath('shop.order_deadline', '2026-06-15T17:00:00+02:00')
            ->assertJsonPath('boxes.0.price_cents', 700)
            ->assertJsonPath('boxes.0.list_value_cents', 2000)
            ->assertJsonPath('boxes.0.min_saving_cents', 1300)
            ->assertJsonPath('boxes.0.stock_available', 3)
            ->assertJsonPath('boxes.0.orderable', true)
            ->assertJsonPath('boxes.0.unavailable_reason', null)
            ->assertJsonPath('boxes.0.description', 'Tre piante grasse')
            ->assertJsonPath('boxes.0.box_type.code', 'MEDIUM')
            ->assertJsonPath('boxes.0.category.code', 'SUCCULENTS')
            ->assertJsonPath('boxes.0.attributes.0', ['code' => 'INDOOR', 'label' => 'Da interno', 'group' => 'LOCATION'])
            ->assertJsonPath('boxes.0.attributes.1.code', 'TOXIC_PETS')
            ->assertJsonPath('rating', ['average' => null, 'count' => 0, 'reviews' => []]);
    }

    public function test_la_scheda_mostra_la_distanza_solo_con_le_coordinate(): void
    {
        $shop = $this->shop('Vivaio Due Torri', 'Bologna', 44.4949, 11.3426);

        $this->getJson("/api/shops/{$shop->id}")->assertOk()->assertJsonPath('shop.distance_m', null);

        $distance = $this->getJson("/api/shops/{$shop->id}?".http_build_query(self::BOLOGNA))->json('shop.distance_m');
        $this->assertGreaterThan(0, $distance);
        $this->assertLessThan(500, $distance);
    }

    public function test_box_esaurite_in_fondo_e_segnate_come_tali(): void
    {
        $shop = $this->shop('Vivaio Rossi', 'Bologna', 44.5, 11.3);
        $this->box($shop, ['price_cents' => 300, 'stock_available' => 0, 'description' => 'Esaurita economica']);
        $this->box($shop, ['price_cents' => 900, 'description' => 'Cara']);
        $this->box($shop, ['price_cents' => 500, 'description' => 'Media']);

        $response = $this->getJson("/api/shops/{$shop->id}")->assertOk();

        $this->assertSame(['Media', 'Cara', 'Esaurita economica'], array_column($response->json('boxes'), 'description'));
        $response->assertJsonPath('boxes.2.sold_out', true)
            ->assertJsonPath('boxes.2.orderable', false)
            ->assertJsonPath('boxes.2.unavailable_reason', 'SOLD_OUT')
            ->assertJsonPath('boxes.0.sold_out', false);
    }

    public function test_dopo_l_orario_limite_le_box_non_sono_piu_ordinabili(): void
    {
        $shop = $this->shop('Vivaio Rossi', 'Bologna', 44.5, 11.3); // ritiro fino alle 19:00: ordini fino alle 17:00
        $this->box($shop);

        // 16:59 a Roma (14:59 UTC): ancora aperto
        Carbon::setTestNow('2026-06-15 14:59:00');
        $this->getJson("/api/shops/{$shop->id}")->assertJsonPath('boxes.0.orderable', true)->assertJsonPath('shop.orders_open', true);
        $this->assertTrue($this->getJson('/api/shops')->json('data.0.orders_open'));

        // 17:00 a Roma: chiuso
        Carbon::setTestNow('2026-06-15 15:00:00');
        $this->getJson("/api/shops/{$shop->id}")
            ->assertJsonPath('boxes.0.orderable', false)
            ->assertJsonPath('boxes.0.unavailable_reason', 'ORDER_DEADLINE_PASSED')
            ->assertJsonPath('shop.orders_open', false);
        $this->assertFalse($this->getJson('/api/shops')->json('data.0.orders_open'));
    }

    public function test_un_negozio_non_vendibile_o_inesistente_da_404(): void
    {
        $pending = $this->sellerWithProfile(Seller::STATUS_PENDING, vat: 'IT55555555553');
        $noStripe = $this->sellerWithProfile(Seller::STATUS_APPROVED, vat: 'IT55555555554');
        $suspended = $this->shop('Sospeso', 'Bologna', 44.5, 11.3, ['verification_status' => Seller::STATUS_SUSPENDED]);

        foreach ([$pending->id, $noStripe->id, $suspended->id, 999999] as $id) {
            $this->getJson("/api/shops/{$id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        }
    }

    public function test_le_box_eliminate_o_di_altri_giorni_non_compaiono_nella_scheda(): void
    {
        $shop = $this->shop('Vivaio Rossi', 'Bologna', 44.5, 11.3);
        $this->box($shop, ['description' => 'Di oggi']);
        $this->box($shop, ['description' => 'Di ieri', 'offer_date' => '2026-06-14']);
        $this->box($shop, ['description' => 'Eliminata'])->delete();

        $this->assertSame(['Di oggi'], array_column($this->getJson("/api/shops/{$shop->id}")->json('boxes'), 'description'));
    }

    public function test_media_e_recensioni_visibili_nella_scheda_e_nella_lista(): void
    {
        $shop = $this->shop('Vivaio Rossi', 'Bologna', 44.5, 11.3);
        $this->box($shop);
        $anna = User::factory()->create(['username' => 'anna']);
        $luca = User::factory()->create(['username' => 'luca']);

        // Le recensioni nascono da un ordine ritirato (giorno 12): qui si inseriscono direttamente
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([[1, $anna, 5, 'Bellissime piante', 'VISIBLE'], [2, $luca, 4, null, 'VISIBLE'], [3, $luca, 1, 'Da rimuovere', 'REMOVED']] as [$order, $user, $rating, $comment, $status]) {
            DB::table('reviews')->insert([
                'order_id' => $order, 'customer_id' => $user->id, 'seller_id' => $shop->id,
                'rating' => $rating, 'comment' => $comment, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $detail = $this->getJson("/api/shops/{$shop->id}")->assertOk()
            ->assertJsonPath('rating.average', 4.5)
            ->assertJsonPath('rating.count', 2)
            ->assertJsonCount(2, 'rating.reviews');
        $this->assertSame(['luca', 'anna'], array_column($detail->json('rating.reviews'), 'author')); // le più recenti prima
        $this->assertSame('Bellissime piante', $detail->json('rating.reviews.1.comment'));

        $this->getJson('/api/shops')->assertJsonPath('data.0.rating', ['average' => 4.5, 'count' => 2]);
    }
}

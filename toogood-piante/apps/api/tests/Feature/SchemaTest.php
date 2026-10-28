<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_i_dati_fissi_e_i_parametri_di_business_sono_presenti(): void
    {
        $this->assertSame(3, DB::table('box_types')->count());
        $this->assertSame(7, DB::table('categories')->count());
        $this->assertSame(10, DB::table('plant_attributes')->count());

        $params = DB::table('config_params')->pluck('value', 'key');
        $this->assertSame('150', $params['fee_cents']);
        $this->assertSame('60', $params['discount_min_pct']);
        $this->assertSame('70', $params['discount_max_pct']);
        $this->assertSame('2', $params['order_cutoff_hours']);
        $this->assertSame('24', $params['payout_delay_hours']);
    }

    public function test_rieseguire_il_seeder_non_duplica_e_non_sovrascrive_i_parametri_modificati(): void
    {
        DB::table('config_params')->where('key', 'fee_cents')->update(['value' => '200']);

        $this->seed(CatalogSeeder::class);

        $this->assertSame(7, DB::table('categories')->count());
        $this->assertSame('200', DB::table('config_params')->where('key', 'fee_cents')->value('value'));
    }

    public function test_lo_stock_disponibile_non_puo_superare_quello_totale(): void
    {
        $this->expectException(QueryException::class);

        $this->insertOffer(['stock_total' => 5, 'stock_available' => 6]);
    }

    public function test_il_decremento_atomico_non_porta_mai_lo_stock_sotto_zero(): void
    {
        $offerId = $this->insertOffer(['stock_total' => 2, 'stock_available' => 2]);

        $decrement = fn (int $qty) => DB::update(
            'UPDATE box_offers SET stock_available = stock_available - ? WHERE id = ? AND stock_available >= ?',
            [$qty, $offerId, $qty],
        );

        $this->assertSame(1, $decrement(1));
        $this->assertSame(1, $decrement(1));
        $this->assertSame(0, $decrement(1), 'la terza vendita deve essere rifiutata');
        $this->assertSame(0, DB::table('box_offers')->where('id', $offerId)->value('stock_available'));
    }

    public function test_la_ricerca_per_distanza_ordina_i_negozi_dal_piu_vicino(): void
    {
        $this->insertSeller('Vivaio Milano', 'Milano', 45.4642, 9.1900);
        $this->insertSeller('Vivaio Bergamo', 'Bergamo', 45.6983, 9.6773);
        $this->insertSeller('Vivaio Roma', 'Roma', 41.9028, 12.4964);

        // Cliente in piazza Duomo a Milano, raggio 100 km
        $lat = 45.4641;
        $lng = 9.1919;
        $rows = DB::select(
            'SELECT business_name, ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) / 1000 AS km
             FROM sellers
             WHERE latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?
               AND ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) <= ?
             ORDER BY km',
            [$lng, $lat, $lat - 1, $lat + 1, $lng - 1.5, $lng + 1.5, $lng, $lat, 100_000],
        );

        $this->assertSame(['Vivaio Milano', 'Vivaio Bergamo'], array_column($rows, 'business_name'));
        $this->assertLessThan(1, $rows[0]->km);
        $this->assertEqualsWithDelta(45, $rows[1]->km, 5);
    }

    public function test_un_utente_non_puo_assegnarsi_il_ruolo_in_massa(): void
    {
        $user = new User(['username' => 'mario', 'email' => 'mario@example.com', 'password' => 'x', 'role' => User::ROLE_ADMIN]);

        $this->assertNull($user->role, 'role non deve essere assegnabile in massa');
    }

    /** @param array<string, mixed> $overrides */
    private function insertOffer(array $overrides = []): int
    {
        $sellerId = $this->insertSeller('Vivaio Prova', 'Milano', 45.4642, 9.19);

        return DB::table('box_offers')->insertGetId(array_merge([
            'seller_id' => $sellerId,
            'offer_date' => '2026-10-28',
            'box_type_id' => DB::table('box_types')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'attributes' => json_encode(['INDOOR']),
            'description' => 'Piante miste',
            'list_value_cents' => 2000,
            'discount_pct' => 65,
            'price_cents' => 700,
            'fee_cents' => 150,
            'stock_total' => 5,
            'stock_available' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertSeller(string $name, string $city, float $lat, float $lng): int
    {
        $user = User::factory()->seller()->create();

        return DB::table('sellers')->insertGetId([
            'user_id' => $user->id,
            'business_name' => $name,
            'vat_number' => 'IT'.str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
            'phone' => '0200000000',
            'address' => "Via Roma 1, $city",
            'city' => $city,
            'latitude' => $lat,
            'longitude' => $lng,
            'opens_at' => '09:00', 'closes_at' => '19:00',
            'pickup_from' => '17:00', 'pickup_to' => '19:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature\Seller;

use App\Models\BoxOffer;
use App\Models\Seller;
use App\Models\User;
use App\Support\Clock;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SellerHelpers;
use Tests\TestCase;

class BoxTest extends TestCase
{
    use RefreshDatabase, SellerHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    /** Venditore approvato e con il conto di pagamento collegato: può pubblicare. */
    private function publisher(string $vat = 'IT22222222222'): Seller
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_APPROVED, vat: $vat);
        $seller->forceFill(['stripe_account_id' => 'acct_test', 'stripe_onboarded_at' => now()])->save();

        return $seller;
    }

    private function today(): string
    {
        return app(Clock::class)->todayLocal()->toDateString();
    }

    /** Gli ID delle liste non sono fissi (l'auto-incremento non si azzera tra un test e l'altro): si cercano per codice. */
    private function idOf(string $table, string $code): int
    {
        return (int) \DB::table($table)->where('code', $code)->value('id');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'box_type_id' => $this->idOf('box_types', 'MEDIUM'),
            'category_id' => $this->idOf('categories', 'SUCCULENTS'),
            'attributes' => ['INDOOR', 'EASY_CARE'],
            'description' => 'Tre piante grasse miste in vaso da 10 cm',
            'list_value_cents' => 2000,
            'discount_pct' => 65,
            'quantity' => 5,
        ], $overrides);
    }

    private function create(Seller $seller, array $overrides = []): array
    {
        return $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload($overrides))
            ->assertCreated()->json();
    }

    public function test_le_opzioni_contengono_liste_e_parametri(): void
    {
        $seller = $this->publisher();

        $this->actingAsUser($seller->user)->getJson('/api/seller/box-options')->assertOk()
            ->assertJsonPath('fee_cents', 150)
            ->assertJsonPath('discount_min_pct', 60)
            ->assertJsonPath('discount_max_pct', 70)
            ->assertJsonPath('today', $this->today())
            ->assertJsonCount(3, 'box_types')
            ->assertJsonCount(10, 'attributes');
    }

    public function test_si_crea_una_box_con_prezzo_calcolato_dal_server(): void
    {
        $seller = $this->publisher();

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload([
            'price_cents' => 1, 'fee_cents' => 0, 'stock_available' => 99, // il client non decide questi valori
        ]))->assertCreated()
            ->assertJsonPath('price_cents', 700)
            ->assertJsonPath('fee_cents', 150)
            ->assertJsonPath('earning_cents', 550)
            ->assertJsonPath('earning_not_positive', false)
            ->assertJsonPath('stock_total', 5)
            ->assertJsonPath('stock_available', 5)
            ->assertJsonPath('offer_date', $this->today())
            ->assertJsonPath('attributes', ['INDOOR', 'EASY_CARE'])
            ->assertJsonPath('category.code', 'SUCCULENTS')
            ->assertJsonPath('box_type.code', 'MEDIUM');
    }

    public function test_senza_attivita_approvata_e_conto_collegato_non_si_pubblica(): void
    {
        $pending = $this->sellerWithProfile(Seller::STATUS_PENDING);
        $this->actingAsUser($pending->user)->postJson('/api/seller/boxes', $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'SELLER_NOT_READY');

        $noStripe = $this->sellerWithProfile(Seller::STATUS_APPROVED, vat: 'IT33333333333');
        $this->actingAsUser($noStripe->user)->postJson('/api/seller/boxes', $this->payload())
            ->assertForbidden()->assertJsonPath('code', 'SELLER_NOT_READY');

        $this->assertDatabaseCount('box_offers', 0);
    }

    public function test_senza_profilo_risponde_409(): void
    {
        $user = User::factory()->seller()->create();

        $this->actingAsUser($user)->getJson('/api/seller/boxes')->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
    }

    public function test_solo_il_venditore_gestisce_le_box(): void
    {
        $customer = User::factory()->create();

        $this->actingAsUser($customer)->getJson('/api/seller/boxes')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_lo_sconto_deve_stare_tra_60_e_70(): void
    {
        $seller = $this->publisher();

        foreach ([59, 71, 0, 100] as $discount) {
            $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['discount_pct' => $discount]))
                ->assertUnprocessable()->assertJsonValidationErrors('discount_pct');
        }
        foreach ([60, 70] as $discount) {
            $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['discount_pct' => $discount]))->assertCreated();
        }
    }

    public function test_la_quantita_deve_essere_almeno_uno(): void
    {
        $seller = $this->publisher();

        foreach ([0, -3, 1000] as $quantity) {
            $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['quantity' => $quantity]))
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
    }

    public function test_campi_obbligatori_e_valori_inesistenti_sono_rifiutati(): void
    {
        $seller = $this->publisher();

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['box_type_id', 'category_id', 'list_value_cents', 'discount_pct', 'quantity'])
            ->assertJsonMissingValidationErrors('description');

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['attributes' => ['INVENTATO'], 'category_id' => 999]))
            ->assertUnprocessable()->assertJsonValidationErrors(['attributes.0', 'category_id']);
    }

    public function test_la_descrizione_e_facoltativa(): void
    {
        $seller = $this->publisher();
        $without = $this->payload();
        unset($without['description']);

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $without)
            ->assertCreated()->assertJsonPath('description', null);

        // Vuota o fatta solo di spazi vale come assente
        foreach (['', '   '] as $empty) {
            $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['description' => $empty]))
                ->assertCreated()->assertJsonPath('description', null);
        }

        // Una descrizione breve è accettata (nessun minimo), una troppo lunga no
        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['description' => 'Mista']))
            ->assertCreated()->assertJsonPath('description', 'Mista');
        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['description' => str_repeat('a', 1001)]))
            ->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_la_descrizione_si_aggiunge_e_si_cancella_in_modifica(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);

        $put = fn (array $data) => $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", $data);

        $put(['description' => null])->assertOk()->assertJsonPath('description', null);
        $put(['description' => '  Due felci  '])->assertOk()->assertJsonPath('description', 'Due felci');
        $put(['description' => ''])->assertOk()->assertJsonPath('description', null);
        // Non inviarla non la tocca
        $put(['description' => 'Tre piante'])->assertOk();
        $put(['quantity' => 4])->assertOk()->assertJsonPath('description', 'Tre piante');
    }

    public function test_con_guadagno_non_positivo_serve_la_conferma_esplicita(): void
    {
        $seller = $this->publisher();
        $low = ['list_value_cents' => 300, 'discount_pct' => 70]; // prezzo 0,90 €, tariffa 1,50 €

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload($low))
            ->assertStatus(409)
            ->assertJsonPath('code', 'LOW_EARNING_CONFIRMATION_REQUIRED')
            ->assertJsonPath('quote.price_cents', 90)
            ->assertJsonPath('quote.earning_cents', -60);
        $this->assertDatabaseCount('box_offers', 0);

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload($low + ['confirm_low_earning' => true]))
            ->assertCreated()->assertJsonPath('earning_cents', -60)->assertJsonPath('earning_not_positive', true);

        // Guadagno esattamente zero: serve comunque la conferma
        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload(['list_value_cents' => 500, 'discount_pct' => 70]))
            ->assertStatus(409)->assertJsonPath('code', 'LOW_EARNING_CONFIRMATION_REQUIRED');
    }

    public function test_la_tariffa_e_una_istantanea_della_configurazione(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);

        \DB::table('config_params')->where('key', 'fee_cents')->update(['value' => '200']);

        $this->actingAsUser($seller->user)->getJson("/api/seller/boxes/{$box['id']}")
            ->assertOk()->assertJsonPath('fee_cents', 150)->assertJsonPath('earning_cents', 550);

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload())
            ->assertCreated()->assertJsonPath('fee_cents', 200)->assertJsonPath('earning_cents', 500);
    }

    public function test_l_elenco_mostra_solo_le_box_del_giorno_e_del_venditore(): void
    {
        $mine = $this->publisher();
        $other = $this->publisher('IT44444444444');
        $this->create($mine);
        $this->create($mine, ['description' => 'Seconda box di oggi']);
        $this->create($other);

        $yesterday = $this->create($mine);
        BoxOffer::whereKey($yesterday['id'])->update(['offer_date' => now()->subDay()->toDateString()]);

        $this->actingAsUser($mine->user)->getJson('/api/seller/boxes')->assertOk()
            ->assertJsonPath('can_publish', true)->assertJsonCount(2, 'data');

        $this->actingAsUser($mine->user)->getJson('/api/seller/boxes?date='.now()->subDay()->toDateString())
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsUser($mine->user)->getJson('/api/seller/boxes?date=ieri')->assertUnprocessable();
    }

    public function test_non_si_vedono_ne_si_modificano_le_box_di_un_altro_venditore(): void
    {
        $mine = $this->publisher();
        $other = $this->publisher('IT44444444444');
        $theirs = $this->create($other);

        $this->actingAsUser($mine->user)->getJson("/api/seller/boxes/{$theirs['id']}")->assertNotFound();
        $this->actingAsUser($mine->user)->putJson("/api/seller/boxes/{$theirs['id']}", ['description' => 'Rubata!'])->assertNotFound();
        $this->actingAsUser($mine->user)->deleteJson("/api/seller/boxes/{$theirs['id']}")->assertNotFound();

        $this->assertSame('Tre piante grasse miste in vaso da 10 cm', BoxOffer::find($theirs['id'])->description);
    }

    public function test_si_modificano_descrizione_attributi_e_categoria(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);

        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", [
            'description' => 'Due felci e un pothos', 'category_id' => $this->idOf('categories', 'HOUSEPLANTS'), 'attributes' => ['INDOOR', 'TOXIC_PETS'],
        ])->assertOk()
            ->assertJsonPath('description', 'Due felci e un pothos')
            ->assertJsonPath('category.code', 'HOUSEPLANTS')
            ->assertJsonPath('attributes', ['INDOOR', 'TOXIC_PETS'])
            ->assertJsonPath('price_cents', 700);
    }

    public function test_senza_vendite_si_possono_cambiare_valore_e_sconto(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);

        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", ['list_value_cents' => 3000, 'discount_pct' => 60])
            ->assertOk()->assertJsonPath('price_cents', 1200)->assertJsonPath('earning_cents', 1050);

        // Anche in modifica, un guadagno non positivo richiede la conferma
        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", ['list_value_cents' => 300, 'discount_pct' => 70])
            ->assertStatus(409)->assertJsonPath('code', 'LOW_EARNING_CONFIRMATION_REQUIRED');
        $this->assertSame(1200, BoxOffer::find($box['id'])->price_cents);
    }

    public function test_dopo_la_prima_vendita_prezzo_e_valore_sono_bloccati(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);
        BoxOffer::whereKey($box['id'])->update(['stock_available' => 3]); // 2 vendute

        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", ['list_value_cents' => 3000])
            ->assertStatus(409)->assertJsonPath('code', 'PRICE_LOCKED');
        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", ['discount_pct' => 60])
            ->assertStatus(409)->assertJsonPath('code', 'PRICE_LOCKED');

        // Rimandare gli stessi valori non è una modifica; la descrizione si può cambiare
        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", [
            'list_value_cents' => 2000, 'discount_pct' => 65, 'description' => 'Descrizione aggiornata',
        ])->assertOk()->assertJsonPath('price_locked', true)->assertJsonPath('sold', 2);
    }

    public function test_la_quantita_puo_solo_diminuire_fino_a_quanto_venduto(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller); // 5 box
        BoxOffer::whereKey($box['id'])->update(['stock_available' => 3]); // 2 vendute

        $put = fn (int $q) => $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", ['quantity' => $q]);

        $put(6)->assertUnprocessable()->assertJsonPath('code', 'QUANTITY_CANNOT_INCREASE');
        $put(1)->assertUnprocessable()->assertJsonPath('code', 'QUANTITY_BELOW_SOLD');

        $put(4)->assertOk()->assertJsonPath('stock_total', 4)->assertJsonPath('stock_available', 2)->assertJsonPath('sold', 2);
        // Portare lo stock a quanto già venduto: la box non è più acquistabile
        $put(2)->assertOk()->assertJsonPath('stock_available', 0)->assertJsonPath('sold_out', true);
    }

    public function test_una_box_di_un_giorno_passato_non_si_modifica(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);
        BoxOffer::whereKey($box['id'])->update(['offer_date' => now()->subDay()->toDateString()]);

        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", ['description' => 'Troppo tardi'])
            ->assertStatus(409)->assertJsonPath('code', 'BOX_NOT_EDITABLE');
    }

    public function test_si_elimina_una_box_senza_vendite(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);

        $this->actingAsUser($seller->user)->deleteJson("/api/seller/boxes/{$box['id']}")->assertNoContent();

        $this->assertSoftDeleted('box_offers', ['id' => $box['id']]);
        $this->actingAsUser($seller->user)->getJson('/api/seller/boxes')->assertJsonCount(0, 'data');
        $this->actingAsUser($seller->user)->getJson("/api/seller/boxes/{$box['id']}")->assertNotFound();
    }

    public function test_con_ordini_la_box_non_si_elimina(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);
        BoxOffer::whereKey($box['id'])->update(['stock_available' => 4]);

        $this->actingAsUser($seller->user)->deleteJson("/api/seller/boxes/{$box['id']}")
            ->assertStatus(409)->assertJsonPath('code', 'BOX_HAS_ORDERS');

        $this->assertNotSoftDeleted('box_offers', ['id' => $box['id']]);
    }

    public function test_un_venditore_sospeso_non_pubblica_ne_modifica(): void
    {
        $seller = $this->publisher();
        $box = $this->create($seller);
        $seller->forceFill(['verification_status' => Seller::STATUS_SUSPENDED])->save();
        $seller->user->refresh(); // ricarica la relazione: in produzione ogni richiesta rilegge il venditore

        $this->actingAsUser($seller->user)->postJson('/api/seller/boxes', $this->payload())->assertForbidden();
        $this->actingAsUser($seller->user)->putJson("/api/seller/boxes/{$box['id']}", ['description' => 'Provo lo stesso'])->assertForbidden();
        $this->actingAsUser($seller->user)->getJson('/api/seller/boxes')->assertOk()->assertJsonPath('can_publish', false);
    }
}

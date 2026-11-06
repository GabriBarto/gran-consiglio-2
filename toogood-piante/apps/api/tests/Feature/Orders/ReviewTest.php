<?php

namespace Tests\Feature\Orders;

use App\Models\Review;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\Concerns\PickupHelpers;
use Tests\TestCase;

/** Recensioni dopo il ritiro (US-C12, US-V17): una per ordine, solo se ritirato. */
class ReviewTest extends TestCase
{
    use OrderHelpers, PickupHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->fakePayments();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function review(User $customer, int $orderId, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($customer)->postJson("/api/orders/{$orderId}/review", $body);
    }

    public function test_dopo_il_ritiro_il_cliente_lascia_una_recensione(): void
    {
        $anna = $this->customer('anna');
        $shop = $this->publishableShop();
        $order = $this->pickedUpOrder($anna, $shop);

        $this->review($anna, $order['id'], ['rating' => 4, 'comment' => '  Piante sane, grazie!  '])
            ->assertCreated()->assertJsonPath('rating', 4)->assertJsonPath('comment', 'Piante sane, grazie!');

        $saved = Review::where('order_id', $order['id'])->firstOrFail();
        $this->assertSame($shop->id, $saved->seller_id);
        $this->assertSame($anna->id, $saved->customer_id);
        $this->assertSame('VISIBLE', $saved->status);
    }

    public function test_il_commento_e_facoltativo(): void
    {
        $anna = $this->customer();
        $order = $this->pickedUpOrder($anna, $this->publishableShop());

        $this->review($anna, $order['id'], ['rating' => 5, 'comment' => '   '])->assertCreated()->assertJsonPath('comment', null);
    }

    public function test_senza_ritiro_non_si_recensisce(): void
    {
        $anna = $this->customer();
        $order = $this->paidOrder($anna, $this->publishableShop());

        $this->review($anna, $order['id'], ['rating' => 5])->assertStatus(409)->assertJsonPath('code', 'REVIEW_NOT_ALLOWED');
        $this->assertSame(0, Review::count());
    }

    public function test_una_sola_recensione_per_ordine(): void
    {
        $anna = $this->customer();
        $order = $this->pickedUpOrder($anna, $this->publishableShop());

        $this->review($anna, $order['id'], ['rating' => 5])->assertCreated();
        $this->review($anna, $order['id'], ['rating' => 1])->assertStatus(409)->assertJsonPath('code', 'ALREADY_REVIEWED');

        $this->assertSame(1, Review::count());
        $this->assertSame(5, (int) Review::first()->rating);
    }

    public function test_il_voto_va_da_1_a_5(): void
    {
        $anna = $this->customer();
        $order = $this->pickedUpOrder($anna, $this->publishableShop());

        foreach ([0, 6, -1, 'molto'] as $bad) {
            $this->review($anna, $order['id'], ['rating' => $bad])->assertUnprocessable()->assertJsonValidationErrors(['rating']);
        }
        $this->review($anna, $order['id'], [])->assertUnprocessable()->assertJsonValidationErrors(['rating']);
        $this->review($anna, $order['id'], ['rating' => 3, 'comment' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors(['comment']);
        $this->assertSame(0, Review::count());
    }

    public function test_non_si_recensisce_l_ordine_di_un_altro_cliente_ne_da_venditore(): void
    {
        $anna = $this->customer('anna');
        $shop = $this->publishableShop();
        $order = $this->pickedUpOrder($anna, $shop);

        $this->review($this->customer('bruno'), $order['id'], ['rating' => 1])->assertNotFound();
        $this->review($shop->user, $order['id'], ['rating' => 5])->assertForbidden();
        $this->assertSame(0, Review::count());
    }

    public function test_l_ordine_dice_se_si_puo_recensire_e_mostra_la_recensione(): void
    {
        $anna = $this->customer();
        $order = $this->pickedUpOrder($anna, $this->publishableShop());

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")
            ->assertOk()->assertJsonPath('can_review', true)->assertJsonPath('review', null);

        $this->review($anna, $order['id'], ['rating' => 3, 'comment' => 'Ok'])->assertCreated();

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")
            ->assertOk()->assertJsonPath('can_review', false)->assertJsonPath('review.rating', 3)->assertJsonPath('review.comment', 'Ok');
    }

    public function test_un_ordine_non_ritirato_non_si_puo_recensire_dall_app(): void
    {
        $anna = $this->customer();
        $order = $this->paidOrder($anna, $this->publishableShop());

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()->assertJsonPath('can_review', false);
    }

    public function test_la_recensione_compare_nella_scheda_del_negozio_e_al_venditore(): void
    {
        $anna = $this->customer('anna');
        $shop = $this->publishableShop();
        $order = $this->pickedUpOrder($anna, $shop);
        $this->review($anna, $order['id'], ['rating' => 4, 'comment' => 'Bella box'])->assertCreated();

        // Pubblica: nome utente, mai email
        $page = $this->asGuest()->getJson("/api/shops/{$shop->id}")->assertOk();
        $page->assertJsonPath('rating.count', 1)->assertJsonPath('rating.average', 4)
            ->assertJsonPath('rating.reviews.0.comment', 'Bella box')->assertJsonPath('rating.reviews.0.author', 'anna');
        $this->assertStringNotContainsString($anna->email, $page->getContent());

        $this->actingAsUser($shop->user)->getJson('/api/seller/reviews')->assertOk()
            ->assertJsonPath('summary.count', 1)->assertJsonPath('summary.average', 4)
            ->assertJsonPath('data.0.comment', 'Bella box')->assertJsonPath('data.0.customer.username', 'anna')
            ->assertJsonPath('data.0.order_number', $order['number']);
    }

    public function test_il_venditore_vede_solo_le_sue_recensioni(): void
    {
        $anna = $this->customer();
        $mine = $this->publishableShop('Mio');
        $other = $this->publishableShop('Altro');
        $this->review($anna, ($o = $this->pickedUpOrder($anna, $other))['id'], ['rating' => 1])->assertCreated();

        $this->actingAsUser($mine->user)->getJson('/api/seller/reviews')->assertOk()
            ->assertJsonPath('summary.count', 0)->assertJsonPath('summary.average', null)->assertJsonCount(0, 'data');
        $this->actingAsUser($anna)->getJson('/api/seller/reviews')->assertForbidden();
    }

    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}

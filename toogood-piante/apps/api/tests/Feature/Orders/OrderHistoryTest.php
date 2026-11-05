<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

/** Storico degli ordini del cliente (US-C11). */
class OrderHistoryTest extends TestCase
{
    use OrderHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-06-15 08:00:00');
        $this->fakePayments();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_senza_ordini_l_elenco_e_vuoto(): void
    {
        $this->actingAsUser($this->customer())->getJson('/api/orders')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('payment_simulated', true);
    }

    public function test_si_vedono_solo_i_propri_ordini_dal_piu_recente(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop, ['stock_total' => 20, 'stock_available' => 20]);
        $anna = $this->customer('anna');
        $luca = $this->customer('luca');

        $first = $this->placeOrder($anna, [$box->id => 1]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$first['id']}/simulate-payment")->assertOk();
        $second = $this->placeOrder($anna, [$box->id => 2]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$second['id']}/simulate-payment")->assertOk();
        $others = $this->placeOrder($luca, [$box->id => 1]);

        $response = $this->actingAsUser($anna)->getJson('/api/orders')->assertOk();

        $this->assertSame([$second['id'], $first['id']], array_column($response->json('data'), 'id'));
        $this->assertNotContains($others['id'], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.status', 'PAID')
            ->assertJsonPath('data.0.total_cents', 1400)
            ->assertJsonPath('data.0.shop.business_name', 'Vivaio Rossi')
            ->assertJsonPath('data.0.items.0.quantity', 2);
    }

    public function test_gli_ordini_scaduti_o_falliti_non_compaiono_quelli_in_attesa_e_rimborsati_si(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop, ['stock_total' => 20, 'stock_available' => 20]);
        $anna = $this->customer('anna');

        $expired = $this->placeOrder($anna, [$box->id => 1]);          // sostituito dal carrello successivo → EXPIRED
        $failed = $this->placeOrder($anna, [$box->id => 1]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$failed['id']}/simulate-payment", ['outcome' => 'failed'])->assertOk();
        $pending = $this->placeOrder($anna, [$box->id => 1]);

        // Un ordine rimborsato (pagamento tardivo): lo si crea per un altro cliente e lo si passa ad Anna
        $refunded = $this->placeOrder($this->customer('sara'), [$box->id => 1]);
        Order::whereKey($refunded['id'])->update(['status' => 'REFUNDED_FULL', 'customer_id' => $anna->id]);

        $this->assertSame('EXPIRED', $this->orderStatus($expired['id']));
        $this->assertSame('PAYMENT_FAILED', $this->orderStatus($failed['id']));

        $data = $this->actingAsUser($anna)->getJson('/api/orders')->assertOk()->json('data');
        $ids = array_column($data, 'id');

        $this->assertEqualsCanonicalizing([$pending['id'], $refunded['id']], $ids);
        $this->assertSame('2026-06-15T08:10:00+00:00', $data[array_search($pending['id'], $ids)]['reserved_until']);
    }

    public function test_l_elenco_e_solo_per_i_clienti(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();

        $shop = $this->publishableShop();
        $this->actingAsUser($shop->user)->getJson('/api/orders')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_l_elenco_si_ferma_a_cinquanta(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $box = $this->makeBox($shop);

        // 55 ordini pagati inseriti direttamente (un acquisto vero ne lascia in attesa uno alla volta)
        for ($i = 0; $i < 55; $i++) {
            $order = new Order;
            $order->customer_id = $anna->id;
            $order->seller_id = $shop->id;
            $order->order_date = '2026-06-15';
            $order->status = Order::PAID;
            $order->total_cents = 700;
            $order->fee_total_cents = 150;
            $order->no_refund_accepted_at = now();
            $order->pickup_window_end_at = now()->addHours(9);
            $order->paid_at = now();
            $order->save();

            $item = new OrderItem;
            $item->order_id = $order->id;
            $item->box_offer_id = $box->id;
            $item->quantity = 1;
            $item->unit_price_cents = 700;
            $item->unit_fee_cents = 150;
            $item->save();
        }

        $this->actingAsUser($anna)->getJson('/api/orders')->assertOk()->assertJsonCount(50, 'data');
    }

    public function test_il_dettaglio_dice_se_il_pagamento_e_simulato(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $anna = $this->customer('anna');
        $order = $this->placeOrder($anna, [$box->id => 1]);

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()->assertJsonPath('payment_simulated', true);

        config(['toogood.payments.simulated' => false]);
        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertJsonPath('payment_simulated', false);
    }
}

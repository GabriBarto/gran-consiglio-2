<?php

namespace Tests\Feature\Orders;

use App\Mail\NewOrderMail;
use App\Mail\OrderConfirmationMail;
use App\Models\BoxOffer;
use App\Models\Order;
use App\Models\Seller;
use App\Models\StockReservation;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use OrderHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        // Lunedì 15 giugno 2026, 10:00 a Roma (08:00 UTC): ritiro 17:00–19:00, si ordina fino alle 17:00
        Carbon::setTestNow('2026-06-15 08:00:00');
        $this->fakePayments();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------- creazione dell'ordine ----------

    public function test_il_checkout_crea_un_ordine_in_attesa_e_tiene_da_parte_le_box(): void
    {
        $shop = $this->publishableShop();
        $a = $this->makeBox($shop, ['price_cents' => 700, 'list_value_cents' => 2000, 'stock_available' => 5]);
        $b = $this->makeBox($shop, ['price_cents' => 400, 'list_value_cents' => 1000, 'stock_available' => 3, 'description' => 'Aromatiche']);
        $customer = $this->customer();

        $response = $this->actingAsUser($customer)->postJson('/api/orders', $this->cart([$a->id => 2, $b->id => 1]))
            ->assertCreated()
            ->assertJsonPath('status', 'PENDING_PAYMENT')
            ->assertJsonPath('total_cents', 1800)                 // 2×7,00 + 1×4,00
            ->assertJsonPath('min_saving_cents', 2 * 1300 + 600)  // listino − prezzo, almeno
            ->assertJsonPath('shop.business_name', 'Vivaio Rossi')
            ->assertJsonPath('shop.pickup_from', '17:00')
            ->assertJsonPath('shop.pickup_to', '19:00')
            ->assertJsonPath('reserved_until', '2026-06-15T08:10:00+00:00')
            ->assertJsonPath('payment.simulated', true)
            ->assertJsonCount(2, 'items');

        $orderId = $response->json('id');
        $this->assertSame('TG-'.str_pad((string) $orderId, 6, '0', STR_PAD_LEFT), $response->json('number'));
        $this->assertSame('pi_test_'.$orderId, $response->json('payment.payment_intent_id'));
        $this->assertNotEmpty($response->json('payment.client_secret'));

        // Stock: scalato subito, e una prenotazione per riga con scadenza a 10 minuti
        $this->assertSame(3, $this->stockOf($a));
        $this->assertSame(2, $this->stockOf($b));
        $this->assertSame(2, StockReservation::where('order_id', $orderId)->count());
        $this->assertTrue(StockReservation::where('order_id', $orderId)->get()->every(fn ($r) => $r->expires_at->equalTo(Carbon::parse('2026-06-15 08:10:00')) && $r->released_at === null));

        $order = Order::find($orderId);
        $this->assertSame(1800, $order->total_cents);
        $this->assertSame(2 * 150 + 150, $order->fee_total_cents);
        $this->assertNotNull($order->no_refund_accepted_at);
        // La fine del ritiro (19:00 a Roma) è salvata in UTC: 17:00
        $this->assertSame('2026-06-15 17:00:00', $order->pickup_window_end_at->utc()->toDateTimeString());
        $this->assertSame('pi_test_'.$orderId, $order->stripe_payment_intent_id);
        $this->assertSame([$orderId], $this->payments->intents);
    }

    public function test_la_risposta_non_mostra_la_tariffa_di_servizio(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop);

        $json = json_encode($this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$box->id => 1]))->assertCreated()->json());

        $this->assertStringNotContainsString('fee', $json);
        $this->assertStringNotContainsString($shop->vat_number, $json);
    }

    public function test_senza_accesso_o_da_venditore_non_si_ordina(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop);

        $this->postJson('/api/orders', $this->cart([$box->id => 1]))->assertUnauthorized();

        $this->actingAsUser($shop->user)->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');

        $this->assertSame(5, $this->stockOf($box));
    }

    public function test_senza_accettare_il_nessun_rimborso_non_si_ordina(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $customer = $this->customer();

        foreach ([false, null] as $accept) {
            $payload = $this->cart([$box->id => 1]);
            if ($accept === null) {
                unset($payload['accept_no_refund']);
            } else {
                $payload['accept_no_refund'] = $accept;
            }
            $this->actingAsUser($customer)->postJson('/api/orders', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('accept_no_refund');
        }

        $this->assertSame(5, $this->stockOf($box));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_carrello_non_valido_e_rifiutato(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $customer = $this->customer();
        $post = fn (array $payload) => $this->actingAsUser($customer)->postJson('/api/orders', $payload);

        $post(['accept_no_refund' => true])->assertUnprocessable()->assertJsonValidationErrors('items');
        $post(['accept_no_refund' => true, 'items' => []])->assertUnprocessable()->assertJsonValidationErrors('items');
        $post($this->cart([$box->id => 0]))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $post($this->cart([$box->id => -2]))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $post(['accept_no_refund' => true, 'items' => [['box_id' => $box->id, 'quantity' => 1], ['box_id' => $box->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.box_id');

        $this->assertDatabaseCount('orders', 0);
    }

    // ---------- stock ----------

    public function test_oltre_lo_stock_non_si_ordina_e_non_si_addebita_nulla(): void
    {
        $box = $this->makeBox($this->publishableShop(), ['stock_available' => 2]);

        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$box->id => 3]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'OUT_OF_STOCK')
            ->assertJsonPath('items.0', ['box_id' => $box->id, 'requested' => 3, 'available' => 2]);

        $this->assertSame(2, $this->stockOf($box));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], $this->payments->intents); // il pagamento non è nemmeno stato creato
    }

    public function test_se_una_sola_box_manca_non_si_prenota_nessuna(): void
    {
        $shop = $this->publishableShop();
        $plenty = $this->makeBox($shop, ['stock_available' => 5]);
        $short = $this->makeBox($shop, ['stock_available' => 1, 'description' => 'Ultima']);

        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$plenty->id => 2, $short->id => 2]))
            ->assertStatus(409)->assertJsonPath('code', 'OUT_OF_STOCK');

        $this->assertSame(5, $this->stockOf($plenty));
        $this->assertSame(1, $this->stockOf($short));
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_piu_clienti_non_vendono_mai_piu_di_quanto_c_e(): void
    {
        $box = $this->makeBox($this->publishableShop(), ['stock_total' => 3, 'stock_available' => 3]);

        $ok = 0;
        $fail = 0;
        foreach (range(1, 6) as $i) {
            $status = $this->actingAsUser($this->customer("cliente{$i}"))->postJson('/api/orders', $this->cart([$box->id => 1]))->status();
            $status === 201 ? $ok++ : $fail++;
        }

        $this->assertSame(3, $ok);
        $this->assertSame(3, $fail);
        $this->assertSame(0, $this->stockOf($box));
        $this->assertSame(3, (int) StockReservation::whereNull('released_at')->sum('quantity'));
    }

    public function test_si_puo_prendere_tutto_lo_stock_residuo_senza_limite_per_cliente(): void
    {
        $box = $this->makeBox($this->publishableShop(), ['stock_available' => 4]);

        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$box->id => 4]))->assertCreated();

        $this->assertSame(0, $this->stockOf($box));
    }

    public function test_rifare_il_carrello_libera_l_ordine_in_attesa_precedente(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop, ['stock_available' => 5]);
        $customer = $this->customer();

        $first = $this->placeOrder($customer, [$box->id => 3]);
        $this->assertSame(2, $this->stockOf($box));

        $second = $this->placeOrder($customer, [$box->id => 1]);

        // Il primo carrello è stato rilasciato (stock tornato) e il suo pagamento annullato: resta una sola box tenuta da parte
        $this->assertSame('EXPIRED', $this->orderStatus($first['id']));
        $this->assertSame('PENDING_PAYMENT', $this->orderStatus($second['id']));
        $this->assertSame(4, $this->stockOf($box));
        $this->assertSame(['pi_test_'.$first['id']], $this->payments->cancelled);
        $this->assertSame(1, Order::where('customer_id', $customer->id)->where('status', 'PENDING_PAYMENT')->count());
    }

    // ---------- regole del negozio e dell'orario ----------

    public function test_un_ordine_contiene_box_di_un_solo_negozio(): void
    {
        $a = $this->makeBox($this->publishableShop('Negozio A'));
        $b = $this->makeBox($this->publishableShop('Negozio B'));

        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$a->id => 1, $b->id => 1]))
            ->assertUnprocessable()->assertJsonPath('code', 'MIXED_SHOPS');

        $this->assertSame(5, $this->stockOf($a));
        $this->assertSame(5, $this->stockOf($b));
    }

    public function test_dopo_l_orario_limite_non_si_ordina(): void
    {
        $box = $this->makeBox($this->publishableShop());

        // 16:59 a Roma: ancora aperto
        Carbon::setTestNow('2026-06-15 14:59:00');
        $this->actingAsUser($this->customer('anna'))->postJson('/api/orders', $this->cart([$box->id => 1]))->assertCreated();

        // 17:00 a Roma: chiuso (US-C05 CA2)
        Carbon::setTestNow('2026-06-15 15:00:00');
        $this->actingAsUser($this->customer('luca'))->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_DEADLINE_PASSED');

        $this->assertSame(4, $this->stockOf($box));
    }

    public function test_box_di_ieri_chiuse_o_eliminate_non_si_ordinano(): void
    {
        $shop = $this->publishableShop();
        $yesterday = $this->makeBox($shop, ['offer_date' => '2026-06-14']);
        $closed = $this->makeBox($shop, ['status' => BoxOffer::STATUS_CLOSED]);
        $deleted = $this->makeBox($shop);
        $deleted->delete();
        $customer = $this->customer();

        foreach ([$yesterday, $closed] as $box) {
            $this->actingAsUser($customer)->postJson('/api/orders', $this->cart([$box->id => 1]))
                ->assertStatus(409)->assertJsonPath('code', 'BOX_NOT_AVAILABLE');
        }
        foreach ([$deleted->id, 999999] as $id) {
            $this->actingAsUser($customer)->postJson('/api/orders', $this->cart([$id => 1]))
                ->assertNotFound()->assertJsonPath('code', 'BOX_NOT_FOUND');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_un_negozio_sospeso_o_senza_conto_non_riceve_ordini(): void
    {
        $suspended = $this->publishableShop('Sospeso');
        $box = $this->makeBox($suspended);
        $suspended->forceFill(['verification_status' => Seller::STATUS_SUSPENDED])->save();

        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'SHOP_NOT_AVAILABLE');

        $this->assertSame(5, $this->stockOf($box));
    }

    public function test_sotto_i_cinquanta_centesimi_stripe_non_puo_incassare(): void
    {
        $box = $this->makeBox($this->publishableShop(), ['price_cents' => 20]);

        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertUnprocessable()->assertJsonPath('code', 'PAYMENT_AMOUNT_TOO_LOW');
        // Due box da 20 centesimi fanno 40: ancora troppo poco; tre fanno 60: ok
        $this->actingAsUser($this->customer('luca'))->postJson('/api/orders', $this->cart([$box->id => 2]))->assertUnprocessable();
        $this->actingAsUser($this->customer('sara'))->postJson('/api/orders', $this->cart([$box->id => 3]))->assertCreated();

        $this->assertSame(2, $this->stockOf($box));
    }

    public function test_se_stripe_non_risponde_le_box_tornano_subito_disponibili(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $this->payments->failCreate = true;

        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$box->id => 2]))
            ->assertStatus(502)->assertJsonPath('code', 'STRIPE_UNAVAILABLE');

        $this->assertSame(5, $this->stockOf($box));
        $this->assertSame(Order::PAYMENT_FAILED, Order::first()->status);
        $this->assertSame(0, StockReservation::whereNull('released_at')->count());
    }

    // ---------- lettura ----------

    public function test_si_legge_solo_il_proprio_ordine(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $anna = $this->customer('anna');
        $order = $this->placeOrder($anna, [$box->id => 1]);

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('number', $order['number'])->assertJsonPath('status', 'PENDING_PAYMENT');

        $this->actingAsUser($this->customer('luca'))->getJson("/api/orders/{$order['id']}")->assertNotFound();
        $this->actingAsUser($anna)->getJson('/api/orders/999999')->assertNotFound();
    }

    public function test_prezzo_e_tariffa_dell_ordine_sono_istantanee(): void
    {
        $box = $this->makeBox($this->publishableShop(), ['price_cents' => 700]);
        $anna = $this->customer('anna');
        $order = $this->placeOrder($anna, [$box->id => 2]);

        // Il venditore cambia il prezzo (non ci sono vendite "bloccate" nel test) e la tariffa cambia in configurazione
        BoxOffer::whereKey($box->id)->update(['price_cents' => 900]);
        \DB::table('config_params')->where('key', 'fee_cents')->update(['value' => '300']);

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('total_cents', 1400)
            ->assertJsonPath('items.0.unit_price_cents', 700);
        $this->assertSame(300, Order::find($order['id'])->fee_total_cents); // 2 × 150 della creazione
    }

    // ---------- pagamento simulato (sviluppo) ----------

    public function test_il_pagamento_simulato_porta_l_ordine_a_pagato_e_invia_le_email(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop);
        $anna = $this->customer('anna');
        $order = $this->placeOrder($anna, [$box->id => 2]);

        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk()
            ->assertJsonPath('status', 'PAID')
            ->assertJsonPath('reserved_until', null);

        $this->assertNotNull(Order::find($order['id'])->paid_at);
        Mail::assertSent(OrderConfirmationMail::class, fn ($m) => $m->hasTo($anna->email) && $m->order->id === $order['id']);
        Mail::assertSent(NewOrderMail::class, fn ($m) => $m->hasTo($shop->user->email));

        // Ripeterlo non cambia nulla e non invia altre email
        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk()->assertJsonPath('status', 'PAID');
        Mail::assertSent(OrderConfirmationMail::class, 1);
        Mail::assertSent(NewOrderMail::class, 1);
        $this->assertSame(3, $this->stockOf($box));
    }

    public function test_il_pagamento_simulato_fallito_libera_le_box(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $anna = $this->customer('anna');
        $order = $this->placeOrder($anna, [$box->id => 2]);
        $this->assertSame(3, $this->stockOf($box));

        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment", ['outcome' => 'failed'])
            ->assertOk()->assertJsonPath('status', 'PAYMENT_FAILED');

        $this->assertSame(5, $this->stockOf($box));
        Mail::assertNothingSent();
    }

    public function test_il_pagamento_simulato_non_esiste_con_stripe_vero_e_non_vale_per_ordini_altrui(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $anna = $this->customer('anna');
        $order = $this->placeOrder($anna, [$box->id => 1]);

        $this->actingAsUser($this->customer('luca'))->postJson("/api/orders/{$order['id']}/simulate-payment")->assertNotFound();

        config(['toogood.payments.simulated' => false]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertNotFound();
        $this->assertSame('PENDING_PAYMENT', $this->orderStatus($order['id']));
    }

    public function test_un_cliente_con_ordine_pagato_puo_ordinare_ancora(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $anna = $this->customer('anna');
        $first = $this->placeOrder($anna, [$box->id => 1]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$first['id']}/simulate-payment")->assertOk();

        // L'ordine pagato non è "in attesa": un nuovo carrello non lo tocca
        $this->placeOrder($anna, [$box->id => 1]);

        $this->assertSame('PAID', $this->orderStatus($first['id']));
        $this->assertSame(3, $this->stockOf($box));
    }
}

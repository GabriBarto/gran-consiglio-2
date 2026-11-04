<?php

namespace Tests\Feature\Orders;

use App\Mail\LatePaymentRefundMail;
use App\Mail\NewOrderMail;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

/** Webhook di Stripe: firma, idempotenza, pagamento fallito e pagamento tardivo con rimborso. */
class StripeWebhookTest extends TestCase
{
    use OrderHelpers, RefreshDatabase;

    private const SECRET = 'whsec_test_segreto';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-06-15 08:00:00');
        config(['toogood.stripe.webhook_secret' => self::SECRET]);
        $this->fakePayments();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un evento nella forma di Stripe, con il suo corpo esatto (la firma si calcola su quello). */
    private function eventBody(string $eventId, string $type, string $intentId): string
    {
        return json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => $type,
            'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent']],
        ]);
    }

    private function sign(string $body, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $t = $timestamp ?? time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    private function deliver(string $body, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature ?? $this->sign($body),
        ], $body);
    }

    /** Un ordine in attesa di pagamento da 2 box; restituisce [id ordine, PaymentIntent, box]. */
    private function pendingOrder(): array
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop);
        $order = $this->placeOrder($this->customer(), [$box->id => 2]);

        return [$order['id'], 'pi_test_'.$order['id'], $box, $shop];
    }

    // ---------- firma ----------

    public function test_senza_firma_valida_non_succede_nulla(): void
    {
        [$orderId, $intent] = $this->pendingOrder();
        $body = $this->eventBody('evt_1', 'payment_intent.succeeded', $intent);

        $this->deliver($body, 'firma-inventata')->assertStatus(400)->assertJsonPath('code', 'INVALID_SIGNATURE');
        $this->deliver($body, $this->sign($body, secret: 'whsec_sbagliato'))->assertStatus(400);
        $this->deliver($body, $this->sign($body, time() - 3600))->assertStatus(400); // firma troppo vecchia (replay)
        $this->call('POST', '/api/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400); // senza intestazione

        // Un corpo modificato dopo la firma non è valido
        $signature = $this->sign($body);
        $this->deliver(str_replace('"evt_1"', '"evt_2"', $body), $signature)->assertStatus(400);

        $this->assertSame('PENDING_PAYMENT', $this->orderStatus($orderId));
        $this->assertDatabaseCount('stripe_events', 0);
        Mail::assertNothingSent();
    }

    // ---------- pagamento riuscito ----------

    public function test_il_pagamento_riuscito_segna_l_ordine_pagato_e_invia_le_email(): void
    {
        [$orderId, $intent, $box, $shop] = $this->pendingOrder();

        $this->deliver($this->eventBody('evt_ok', 'payment_intent.succeeded', $intent))
            ->assertOk()->assertJsonPath('outcome', 'paid');

        $order = Order::find($orderId);
        $this->assertSame('PAID', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(3, $this->stockOf($box)); // lo stock resta scalato
        $this->assertNotNull(DB::table('stripe_events')->where('id', 'evt_ok')->value('processed_at'));

        Mail::assertSent(OrderConfirmationMail::class, fn ($m) => $m->hasTo($order->customer->email));
        Mail::assertSent(NewOrderMail::class, fn ($m) => $m->hasTo($shop->user->email));
    }

    public function test_lo_stesso_evento_ripetuto_non_ha_effetto(): void
    {
        [$orderId, $intent] = $this->pendingOrder();
        $body = $this->eventBody('evt_dup', 'payment_intent.succeeded', $intent);

        $this->deliver($body)->assertOk()->assertJsonPath('outcome', 'paid');
        $this->deliver($body)->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->deliver($body)->assertOk()->assertJsonPath('outcome', 'duplicate');

        Mail::assertSent(OrderConfirmationMail::class, 1);
        Mail::assertSent(NewOrderMail::class, 1);
        $this->assertSame(1, DB::table('stripe_events')->count());
        $this->assertSame('PAID', $this->orderStatus($orderId));
    }

    public function test_un_altro_evento_per_un_ordine_gia_pagato_viene_ignorato(): void
    {
        [$orderId, $intent] = $this->pendingOrder();

        $this->deliver($this->eventBody('evt_a', 'payment_intent.succeeded', $intent))->assertOk();
        $this->deliver($this->eventBody('evt_b', 'payment_intent.succeeded', $intent))->assertOk()->assertJsonPath('outcome', 'ignored');

        Mail::assertSent(OrderConfirmationMail::class, 1);
        $this->assertSame([], $this->payments->refunded); // un doppio evento non produce rimborsi
        $this->assertSame('PAID', $this->orderStatus($orderId));
    }

    public function test_un_pagamento_sconosciuto_o_un_evento_non_gestito_da_200_senza_fare_nulla(): void
    {
        [$orderId, $intent] = $this->pendingOrder();

        $this->deliver($this->eventBody('evt_x', 'payment_intent.succeeded', 'pi_inesistente'))
            ->assertOk()->assertJsonPath('outcome', 'unknown');
        $this->deliver($this->eventBody('evt_y', 'charge.refunded', $intent))
            ->assertOk()->assertJsonPath('outcome', 'ignored');

        $this->assertSame('PENDING_PAYMENT', $this->orderStatus($orderId));
        Mail::assertNothingSent();
    }

    public function test_il_pagamento_arrivato_dopo_la_scadenza_ma_prima_della_liberazione_si_onora(): void
    {
        [$orderId, $intent, $box] = $this->pendingOrder();

        // Sono passati 12 minuti ma il comando di scadenza non è ancora girato: le box sono ancora tenute da parte
        Carbon::setTestNow('2026-06-15 08:12:00');
        $this->deliver($this->eventBody('evt_late_ok', 'payment_intent.succeeded', $intent))->assertOk()->assertJsonPath('outcome', 'paid');

        $this->assertSame('PAID', $this->orderStatus($orderId));
        $this->artisan('reservations:release')->assertSuccessful();
        $this->assertSame('PAID', $this->orderStatus($orderId)); // il comando non tocca gli ordini pagati
        $this->assertSame(3, $this->stockOf($box));
        $this->assertSame([], $this->payments->refunded);
    }

    // ---------- pagamento fallito ----------

    public function test_il_pagamento_fallito_libera_lo_stock(): void
    {
        [$orderId, $intent, $box] = $this->pendingOrder();
        $this->assertSame(3, $this->stockOf($box));

        $this->deliver($this->eventBody('evt_fail', 'payment_intent.payment_failed', $intent))
            ->assertOk()->assertJsonPath('outcome', 'released');

        $this->assertSame('PAYMENT_FAILED', $this->orderStatus($orderId));
        $this->assertSame(5, $this->stockOf($box));
        $this->assertSame([$intent], $this->payments->cancelled);
        Mail::assertNothingSent();

        // Ripetere l'evento (anche con un altro id) non restituisce lo stock due volte
        $this->deliver($this->eventBody('evt_fail2', 'payment_intent.payment_failed', $intent))->assertOk()->assertJsonPath('outcome', 'ignored');
        $this->assertSame(5, $this->stockOf($box));
    }

    // ---------- pagamento tardivo ----------

    public function test_un_pagamento_dopo_la_liberazione_viene_rimborsato_per_intero(): void
    {
        [$orderId, $intent, $box] = $this->pendingOrder();
        $customer = Order::find($orderId)->customer;

        Carbon::setTestNow('2026-06-15 08:15:00');
        $this->artisan('reservations:release')->assertSuccessful();
        $this->assertSame('EXPIRED', $this->orderStatus($orderId));
        $this->assertSame(5, $this->stockOf($box));

        // Ora arriva il pagamento (la carta era stata autorizzata poco prima)
        $this->deliver($this->eventBody('evt_late', 'payment_intent.succeeded', $intent))
            ->assertOk()->assertJsonPath('outcome', 'late_refund');

        $this->assertSame('REFUNDED_FULL', $this->orderStatus($orderId));
        $this->assertSame([$intent], $this->payments->refunded);
        $this->assertSame(5, $this->stockOf($box)); // le box restano libere per gli altri
        Mail::assertSent(LatePaymentRefundMail::class, fn ($m) => $m->hasTo($customer->email));
        Mail::assertNotSent(OrderConfirmationMail::class);
        Mail::assertNotSent(NewOrderMail::class);

        // Lo stesso evento di nuovo: nessun secondo rimborso
        $this->deliver($this->eventBody('evt_late', 'payment_intent.succeeded', $intent))->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->assertCount(1, $this->payments->refunded);
    }

    public function test_se_il_rimborso_fallisce_stripe_riprova_e_l_evento_non_e_perso(): void
    {
        [$orderId, $intent] = $this->pendingOrder();
        Carbon::setTestNow('2026-06-15 08:15:00');
        $this->artisan('reservations:release')->assertSuccessful();

        $body = $this->eventBody('evt_retry', 'payment_intent.succeeded', $intent);

        // Stripe non risponde: il webhook va in errore (così Stripe riprova) e l'evento non è segnato come elaborato
        $this->payments->failRefund = true;
        $this->deliver($body)->assertStatus(500);
        $this->assertNull(DB::table('stripe_events')->where('id', 'evt_retry')->value('processed_at'));
        $this->assertSame('EXPIRED', $this->orderStatus($orderId));

        // Al secondo tentativo il rimborso riesce
        $this->payments->failRefund = false;
        $this->deliver($body)->assertOk()->assertJsonPath('outcome', 'late_refund');
        $this->assertSame('REFUNDED_FULL', $this->orderStatus($orderId));
        $this->assertNotNull(DB::table('stripe_events')->where('id', 'evt_retry')->value('processed_at'));
    }

    public function test_dopo_un_pagamento_fallito_un_successo_tardivo_viene_rimborsato(): void
    {
        [$orderId, $intent] = $this->pendingOrder();

        $this->deliver($this->eventBody('evt_f', 'payment_intent.payment_failed', $intent))->assertOk();
        $this->deliver($this->eventBody('evt_s', 'payment_intent.succeeded', $intent))->assertOk()->assertJsonPath('outcome', 'late_refund');

        $this->assertSame('REFUNDED_FULL', $this->orderStatus($orderId));
        $this->assertSame([$intent], $this->payments->refunded);
    }
}

<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\StockReservation;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

/** Comando `reservations:release`: le box di un ordine non pagato tornano disponibili alla scadenza. */
class ReservationReleaseTest extends TestCase
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

    public function test_prima_della_scadenza_non_si_libera_nulla(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $order = $this->placeOrder($this->customer(), [$box->id => 2]);

        Carbon::setTestNow('2026-06-15 08:09:59');
        $this->artisan('reservations:release')->expectsOutput('Ordini scaduti liberati: 0')->assertSuccessful();

        $this->assertSame('PENDING_PAYMENT', $this->orderStatus($order['id']));
        $this->assertSame(3, $this->stockOf($box));
    }

    public function test_alla_scadenza_le_box_tornano_disponibili_e_il_pagamento_e_annullato(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $order = $this->placeOrder($this->customer(), [$box->id => 2]);

        Carbon::setTestNow('2026-06-15 08:10:00'); // 10 minuti esatti
        $this->artisan('reservations:release')->expectsOutput('Ordini scaduti liberati: 1')->assertSuccessful();

        $this->assertSame('EXPIRED', $this->orderStatus($order['id']));
        $this->assertSame(5, $this->stockOf($box));
        $this->assertSame(0, StockReservation::whereNull('released_at')->count());
        $this->assertSame(['pi_test_'.$order['id']], $this->payments->cancelled);
    }

    public function test_eseguirlo_due_volte_non_restituisce_lo_stock_due_volte(): void
    {
        $box = $this->makeBox($this->publishableShop(), ['stock_total' => 5, 'stock_available' => 5]);
        $this->placeOrder($this->customer(), [$box->id => 2]);

        Carbon::setTestNow('2026-06-15 08:30:00');
        $this->artisan('reservations:release')->assertSuccessful();
        $this->artisan('reservations:release')->expectsOutput('Ordini scaduti liberati: 0')->assertSuccessful();

        $this->assertSame(5, $this->stockOf($box)); // mai oltre lo stock totale
    }

    public function test_gli_ordini_pagati_non_si_toccano(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $anna = $this->customer('anna');
        $order = $this->placeOrder($anna, [$box->id => 2]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk();

        Carbon::setTestNow('2026-06-15 09:00:00');
        $this->artisan('reservations:release')->expectsOutput('Ordini scaduti liberati: 0')->assertSuccessful();

        $this->assertSame('PAID', $this->orderStatus($order['id']));
        $this->assertSame(3, $this->stockOf($box));
    }

    public function test_libera_solo_gli_ordini_scaduti(): void
    {
        $box = $this->makeBox($this->publishableShop(), ['stock_available' => 10, 'stock_total' => 10]);
        $old = $this->placeOrder($this->customer('anna'), [$box->id => 2]);

        Carbon::setTestNow('2026-06-15 08:08:00'); // il secondo ordine scade alle 08:18
        $recent = $this->placeOrder($this->customer('luca'), [$box->id => 3]);

        Carbon::setTestNow('2026-06-15 08:11:00');
        $this->artisan('reservations:release')->expectsOutput('Ordini scaduti liberati: 1')->assertSuccessful();

        $this->assertSame('EXPIRED', $this->orderStatus($old['id']));
        $this->assertSame('PENDING_PAYMENT', $this->orderStatus($recent['id']));
        $this->assertSame(7, $this->stockOf($box)); // 10 − 3 ancora tenute da parte
    }

    public function test_se_stripe_non_risponde_all_annullamento_lo_stock_torna_comunque(): void
    {
        $box = $this->makeBox($this->publishableShop());
        $order = $this->placeOrder($this->customer(), [$box->id => 2]);

        // L'annullamento è "al meglio": un errore non deve lasciare le box bloccate
        $this->payments = new class extends \Tests\Concerns\FakePayments
        {
            public function cancelIntent(string $intentId): void
            {
                throw new \RuntimeException('Stripe non risponde');
            }
        };
        $this->app->instance(\App\Services\Stripe\PaymentService::class, $this->payments);
        app()->forgetInstance(\App\Services\Orders\OrderLifecycle::class);

        Carbon::setTestNow('2026-06-15 08:11:00');
        $this->artisan('reservations:release')->assertSuccessful();

        $this->assertSame('EXPIRED', $this->orderStatus($order['id']));
        $this->assertSame(5, $this->stockOf($box));
        $this->assertSame(1, Order::where('status', 'EXPIRED')->count());
    }
}

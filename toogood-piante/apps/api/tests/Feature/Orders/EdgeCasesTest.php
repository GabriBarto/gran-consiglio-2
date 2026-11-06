<?php

namespace Tests\Feature\Orders;

use App\Models\PickupToken;
use App\Models\Seller;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

/**
 * Casi limite del giorno 13: cambio di ora legale e fasce di ritiro corte.
 * Il negozio ritira dalle 17:00 alle 19:00 a Roma, ordini fino alle 17:00.
 */
class EdgeCasesTest extends TestCase
{
    use OrderHelpers, RefreshDatabase;

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

    /** Ordine pagato alle $at (UTC) da $shop; restituisce [id ordine, QR]. */
    private function paidAt(string $at, Seller $shop): array
    {
        Carbon::setTestNow($at);
        $anna = $this->customer('anna'.random_int(1000, 9999));
        $box = $this->makeBox($shop);
        $order = $this->placeOrder($anna, [$box->id => 1]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk()->assertJsonPath('status', 'PAID');
        $ticket = $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}/pickup")->assertOk()->json();

        return [$order['id'], $ticket['qr']];
    }

    private function scanAt(string $at, Seller $shop, string $qr): \Illuminate\Testing\TestResponse
    {
        Carbon::setTestNow($at);

        return $this->actingAsUser($shop->user)->postJson('/api/seller/pickup/scan', ['qr' => $qr]);
    }

    // ---------- ora legale ----------

    public function test_il_giorno_in_cui_inizia_l_ora_legale_la_fascia_si_sposta_di_un_ora_in_utc(): void
    {
        // 29 marzo 2026: alle 02:00 le lancette vanno avanti. 17:00 a Roma sono le 15:00 UTC (il giorno prima erano le 16:00)
        $shop = $this->publishableShop();
        [$orderId, $qr] = $this->paidAt('2026-03-29 08:00:00', $shop);

        $token = PickupToken::where('order_id', $orderId)->firstOrFail();
        $this->assertSame('2026-03-29 15:00:00', $token->valid_from->toDateTimeString());
        $this->assertSame('2026-03-29 17:00:00', $token->valid_until->toDateTimeString());

        $this->scanAt('2026-03-29 14:59:59', $shop, $qr)->assertStatus(409)->assertJsonPath('code', 'NOT_YET_VALID')
            ->assertJsonPath('valid_from', '2026-03-29T17:00:00+02:00');
        $this->scanAt('2026-03-29 15:00:00', $shop, $qr)->assertOk()->assertJsonPath('order.status', 'PICKED_UP');
    }

    public function test_il_giorno_in_cui_finisce_l_ora_legale_la_fascia_si_sposta_di_un_ora_in_utc(): void
    {
        // 25 ottobre 2026: alle 03:00 le lancette tornano indietro. 17:00 a Roma sono le 16:00 UTC
        $shop = $this->publishableShop();
        [$orderId, $qr] = $this->paidAt('2026-10-25 08:00:00', $shop);

        $token = PickupToken::where('order_id', $orderId)->firstOrFail();
        $this->assertSame('2026-10-25 16:00:00', $token->valid_from->toDateTimeString());
        $this->assertSame('2026-10-25 18:00:00', $token->valid_until->toDateTimeString());

        $this->scanAt('2026-10-25 15:59:59', $shop, $qr)->assertStatus(409)->assertJsonPath('code', 'NOT_YET_VALID');
        $this->scanAt('2026-10-25 18:00:01', $shop, $qr)->assertStatus(409)->assertJsonPath('code', 'EXPIRED');
        $this->scanAt('2026-10-25 18:00:00', $shop, $qr)->assertOk();
    }

    public function test_l_orario_limite_segue_l_ora_di_roma_nei_giorni_del_cambio(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop, ['stock_total' => 10, 'stock_available' => 10]);

        // Ora legale (29 marzo): ordini fino alle 17:00 a Roma = 15:00 UTC
        Carbon::setTestNow('2026-03-29 14:59:00');
        $box->forceFill(['offer_date' => '2026-03-29'])->save();
        $this->actingAsUser($this->customer('anna'))->postJson('/api/orders', $this->cart([$box->id => 1]))->assertCreated();
        Carbon::setTestNow('2026-03-29 15:00:00');
        $this->actingAsUser($this->customer('luca'))->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_DEADLINE_PASSED');

        // Ora solare (25 ottobre): le 17:00 a Roma sono le 16:00 UTC
        $box->forceFill(['offer_date' => '2026-10-25'])->save();
        Carbon::setTestNow('2026-10-25 15:59:00');
        $this->actingAsUser($this->customer('sara'))->postJson('/api/orders', $this->cart([$box->id => 1]))->assertCreated();
        Carbon::setTestNow('2026-10-25 16:00:00');
        $this->actingAsUser($this->customer('gino'))->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_DEADLINE_PASSED');
    }

    // ---------- fascia di ritiro corta ----------

    public function test_con_una_fascia_corta_si_chiude_l_ordine_prima_che_inizi_il_ritiro(): void
    {
        // Ritiro 17:00–18:00: ordini fino alle 16:00 (due ore prima della fine), cioè un'ora prima dell'apertura
        $shop = $this->publishableShop();
        $shop->forceFill(['pickup_from' => '17:00:00', 'pickup_to' => '18:00:00'])->save();
        Carbon::setTestNow('2026-06-15 13:59:00'); // 15:59 a Roma
        $box = $this->makeBox($shop, ['stock_total' => 10, 'stock_available' => 10]);
        $this->actingAsUser($this->customer('anna'))->postJson('/api/orders', $this->cart([$box->id => 1]))->assertCreated();

        Carbon::setTestNow('2026-06-15 14:00:00'); // 16:00 a Roma
        $this->actingAsUser($this->customer('luca'))->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_DEADLINE_PASSED');
    }

    public function test_una_fascia_corta_si_ritira_comunque_nei_suoi_orari(): void
    {
        $shop = $this->publishableShop();
        $shop->forceFill(['pickup_from' => '17:00:00', 'pickup_to' => '17:30:00'])->save();
        [, $qr] = $this->paidAt('2026-06-15 08:00:00', $shop);

        // 17:00–17:30 a Roma = 15:00–15:30 UTC
        $this->scanAt('2026-06-15 14:59:00', $shop, $qr)->assertStatus(409)->assertJsonPath('code', 'NOT_YET_VALID');
        $this->scanAt('2026-06-15 15:31:00', $shop, $qr)->assertStatus(409)->assertJsonPath('code', 'EXPIRED');
        $this->scanAt('2026-06-15 15:15:00', $shop, $qr)->assertOk();
    }
}

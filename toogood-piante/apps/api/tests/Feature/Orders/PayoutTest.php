<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Payout;
use App\Models\Seller;
use App\Models\User;
use App\Services\Stripe\TransferService;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

/**
 * Pagamento ritardato al venditore (RB-11) e riepilogo incassi (US-V16).
 * Ritiro 17:00–19:00 a Roma (15:00–17:00 UTC): il payout è dovuto il giorno dopo alle 17:00 UTC.
 */
class PayoutTest extends TestCase
{
    use OrderHelpers, RefreshDatabase;

    private const ORDERED_AT = '2026-06-15 08:00:00';

    private const DUE = '2026-06-16 17:00:00';

    private FakeTransfers $transfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow(self::ORDERED_AT);
        $this->fakePayments();
        Mail::fake();
        $this->transfers = new FakeTransfers;
        $this->app->instance(TransferService::class, $this->transfers);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un ordine pagato. $price è il prezzo della box in centesimi (la tariffa è 150). */
    private function paid(User $customer, Seller $shop, int $quantity = 1, int $price = 700): array
    {
        $box = $this->makeBox($shop, ['stock_total' => 50, 'stock_available' => 50, 'price_cents' => $price]);
        $order = $this->placeOrder($customer, [$box->id => $quantity]);
        $this->actingAsUser($customer)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk();

        return $order;
    }

    private function runPayouts(string $at = self::DUE): void
    {
        Carbon::setTestNow($at);
        $this->artisan('orders:close-pickup')->assertSuccessful();
        $this->artisan('payouts:run')->assertSuccessful();
    }

    private function payoutOf(int $orderId): Payout
    {
        return Payout::where('order_id', $orderId)->firstOrFail();
    }

    // ---------- programmazione ----------

    public function test_il_pagamento_di_un_ordine_programma_il_payout_a_fine_fascia_piu_24_ore(): void
    {
        $order = $this->paid($this->customer(), $this->publishableShop(), 2);

        $payout = $this->payoutOf($order['id']);
        $this->assertSame(Payout::SCHEDULED, $payout->status);
        // 2 box × (7,00 − 1,50)
        $this->assertSame(1100, $payout->amount_cents);
        $this->assertSame(self::DUE, $payout->scheduled_at->toDateTimeString());
    }

    public function test_pagare_due_volte_lo_stesso_ordine_non_crea_due_payout(): void
    {
        $anna = $this->customer();
        $order = $this->paid($anna, $this->publishableShop());

        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment");

        $this->assertSame(1, Payout::count());
    }

    public function test_il_ritardo_si_legge_dalla_configurazione(): void
    {
        \DB::table('config_params')->where('key', 'payout_delay_hours')->update(['value' => '1']);

        $order = $this->paid($this->customer(), $this->publishableShop());

        $this->assertSame('2026-06-15 18:00:00', $this->payoutOf($order['id'])->scheduled_at->toDateTimeString());
    }

    // ---------- esecuzione ----------

    public function test_prima_della_scadenza_non_parte_nulla(): void
    {
        $order = $this->paid($this->customer(), $this->publishableShop());

        $this->runPayouts('2026-06-16 16:59:59');

        $this->assertSame(Payout::SCHEDULED, $this->payoutOf($order['id'])->status);
        $this->assertSame([], $this->transfers->sent);
    }

    public function test_alla_scadenza_il_guadagno_va_al_conto_del_venditore(): void
    {
        $order = $this->paid($this->customer(), $this->publishableShop());

        $this->runPayouts();

        $payout = $this->payoutOf($order['id']);
        $this->assertSame(Payout::SENT, $payout->status);
        $this->assertSame('tr_test_'.$payout->id, $payout->stripe_transfer_id);
        $this->assertSame(self::DUE, $payout->sent_at->toDateTimeString());
        $this->assertSame([['acct_test', 550]], $this->transfers->sent);
    }

    public function test_viene_pagato_anche_l_ordine_non_ritirato(): void
    {
        $order = $this->paid($this->customer(), $this->publishableShop());

        $this->runPayouts();

        $this->assertSame(Order::NOT_PICKED_UP, $this->orderStatus($order['id']));
        $this->assertSame(Payout::SENT, $this->payoutOf($order['id'])->status);
    }

    public function test_viene_pagato_anche_l_ordine_ritirato(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer();
        $order = $this->paid($anna, $shop);
        $ticket = $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}/pickup")->json();
        Carbon::setTestNow('2026-06-15 16:00:00');
        $this->actingAsUser($shop->user)->postJson('/api/seller/pickup/scan', ['qr' => $ticket['qr']])->assertOk();

        $this->runPayouts();

        $this->assertSame(Order::PICKED_UP, $this->orderStatus($order['id']));
        $this->assertSame(Payout::SENT, $this->payoutOf($order['id'])->status);
    }

    public function test_rilanciare_il_comando_non_paga_due_volte(): void
    {
        $this->paid($this->customer(), $this->publishableShop());

        $this->runPayouts();
        $this->artisan('payouts:run')->assertSuccessful();

        $this->assertCount(1, $this->transfers->sent);
    }

    public function test_guadagno_negativo_non_si_trasferisce_e_non_si_addebita(): void
    {
        // Box a 1,00 €: tariffa 1,50 € → guadagno −0,50 €
        $order = $this->paid($this->customer(), $this->publishableShop(), 1, 100);

        $this->assertSame(0, $this->payoutOf($order['id'])->amount_cents);
        $this->runPayouts();

        $this->assertSame(Payout::SKIPPED, $this->payoutOf($order['id'])->status);
        $this->assertSame([], $this->transfers->sent);
    }

    public function test_l_importo_si_calcola_per_box_senza_compensare_le_perdite(): void
    {
        $shop = $this->publishableShop();
        $cheap = $this->makeBox($shop, ['stock_total' => 50, 'stock_available' => 50, 'price_cents' => 100]);
        $good = $this->makeBox($shop, ['stock_total' => 50, 'stock_available' => 50, 'price_cents' => 700]);
        $anna = $this->customer();
        $order = $this->placeOrder($anna, [$cheap->id => 1, $good->id => 1]);
        $this->actingAsUser($anna)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk();

        // max(0, 1,00 − 1,50) + max(0, 7,00 − 1,50) = 5,50 €, non 5,00 €
        $this->assertSame(550, $this->payoutOf($order['id'])->amount_cents);
    }

    public function test_un_errore_di_stripe_non_ferma_gli_altri_e_si_riprova(): void
    {
        $shop = $this->publishableShop();
        $first = $this->paid($this->customer('anna'), $shop);
        $second = $this->paid($this->customer('luca'), $shop);
        $this->transfers->failFor[] = $first['id'];

        // Il comando segnala l'errore (esce con 1) ma intanto paga gli altri
        Carbon::setTestNow(self::DUE);
        $this->artisan('orders:close-pickup')->assertSuccessful();
        $this->artisan('payouts:run')->assertFailed();

        $this->assertSame(Payout::SCHEDULED, $this->payoutOf($first['id'])->status);
        $this->assertSame(Payout::SENT, $this->payoutOf($second['id'])->status);

        $this->transfers->failFor = [];
        $this->artisan('payouts:run')->assertSuccessful();

        $this->assertSame(Payout::SENT, $this->payoutOf($first['id'])->status);
        $this->assertCount(2, $this->transfers->sent);
    }

    public function test_con_un_reclamo_aperto_il_payout_resta_sospeso(): void
    {
        $order = $this->paid($this->customer(), $this->publishableShop());
        Order::whereKey($order['id'])->update(['status' => 'IN_COMPLAINT']);

        Carbon::setTestNow(self::DUE);
        $this->artisan('payouts:run')->assertSuccessful();

        $this->assertSame(Payout::HELD, $this->payoutOf($order['id'])->status);
        $this->assertSame([], $this->transfers->sent);
    }

    public function test_un_ordine_rimborsato_per_intero_non_paga_il_venditore(): void
    {
        $order = $this->paid($this->customer(), $this->publishableShop());
        Order::whereKey($order['id'])->update(['status' => Order::REFUNDED_FULL]);

        Carbon::setTestNow(self::DUE);
        $this->artisan('payouts:run')->assertSuccessful();

        $this->assertSame(Payout::SKIPPED, $this->payoutOf($order['id'])->status);
        $this->assertSame([], $this->transfers->sent);
    }

    public function test_gli_ordini_pagati_prima_della_funzione_ricevono_il_loro_payout(): void
    {
        $order = $this->paid($this->customer(), $this->publishableShop());
        Payout::where('order_id', $order['id'])->delete();

        $this->runPayouts();

        $this->assertSame(Payout::SENT, $this->payoutOf($order['id'])->status);
    }

    public function test_un_ordine_mai_pagato_non_ha_payout(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop);
        $this->placeOrder($this->customer(), [$box->id => 1]);

        $this->runPayouts();

        $this->assertSame(0, Payout::count());
    }

    // ---------- riepilogo incassi ----------

    public function test_il_riepilogo_distingue_trasferito_in_attesa_e_sospeso(): void
    {
        $shop = $this->publishableShop();
        $sent = $this->paid($this->customer('anna'), $shop);            // 5,50 €
        $this->runPayouts();                                            // anna viene pagata
        Carbon::setTestNow('2026-06-17 08:00:00');
        $held = $this->paid($this->customer('luca'), $shop, 2);         // 11,00 €, sospeso
        Order::whereKey($held['id'])->update(['status' => 'IN_COMPLAINT']);
        $pending = $this->paid($this->customer('sara'), $shop);         // 5,50 €, in attesa

        $json = $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk()->json();

        $this->assertSame(3, $json['summary']['orders']);
        $this->assertSame(550, $json['summary']['transferred_cents']);
        $this->assertSame(550, $json['summary']['pending_cents']);
        $this->assertSame(1100, $json['summary']['held_cents']);
        $this->assertSame(4 * 700, $json['summary']['gross_cents']);
        $this->assertSame(4 * 150, $json['summary']['fees_cents']);
        $this->assertSame(4 * 550, $json['summary']['earning_cents']);
        $this->assertCount(3, $json['data']);
        $this->assertEqualsCanonicalizing(
            [$sent['id'] => 'SENT', $held['id'] => 'HELD', $pending['id'] => 'SCHEDULED'],
            array_column($json['data'], 'payout_status', 'order_id'),
        );
    }

    public function test_il_riepilogo_mostra_il_guadagno_negativo_com_e(): void
    {
        $shop = $this->publishableShop();
        $this->paid($this->customer(), $shop, 2, 100); // 2 × (1,00 − 1,50) = −1,00 €

        $json = $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk()->json();

        $this->assertSame(-100, $json['summary']['earning_cents']);
        $this->assertSame(-100, $json['data'][0]['earning_cents']);
        $this->assertSame(0, $json['data'][0]['payout_cents']);
        $this->assertSame(0, $json['summary']['pending_cents']);
    }

    public function test_il_riepilogo_non_conta_gli_ordini_rimborsati_per_intero(): void
    {
        $shop = $this->publishableShop();
        $order = $this->paid($this->customer(), $shop);
        Order::whereKey($order['id'])->update(['status' => Order::REFUNDED_FULL]);

        $json = $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk()->json();

        $this->assertSame(0, $json['summary']['orders']);
        $this->assertSame([], $json['data']);
    }

    public function test_ogni_venditore_vede_solo_i_suoi_incassi(): void
    {
        $mine = $this->publishableShop('Mio');
        $other = $this->publishableShop('Altro');
        $this->paid($this->customer('anna'), $other);

        $json = $this->actingAsUser($mine->user)->getJson('/api/seller/earnings')->assertOk()->json();

        $this->assertSame(0, $json['summary']['orders']);
    }

    public function test_il_riepilogo_non_espone_dati_del_cliente(): void
    {
        $shop = $this->publishableShop();
        $this->paid($this->customer('anna'), $shop);

        $body = $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk()->getContent();

        $this->assertStringNotContainsString('anna', $body);
        $this->assertStringNotContainsString('@', $body);
    }

    public function test_il_riepilogo_e_solo_per_i_venditori(): void
    {
        $this->actingAsUser($this->customer())->getJson('/api/seller/earnings')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/seller/earnings')->assertUnauthorized();
    }
}

/** Trasferimenti finti: registrano conto e importo, e possono fallire per certi ordini. */
class FakeTransfers extends TransferService
{
    /** @var list<array{string, int}> */
    public array $sent = [];

    /** @var list<int> id degli ordini per cui Stripe non risponde */
    public array $failFor = [];

    public function send($payout, string $accountId): string
    {
        if (in_array($payout->order_id, $this->failFor, true)) {
            throw new RuntimeException('Stripe non risponde');
        }
        $this->sent[] = [$accountId, $payout->amount_cents];

        return 'tr_test_'.$payout->id;
    }
}

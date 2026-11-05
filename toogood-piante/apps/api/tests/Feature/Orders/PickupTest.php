<?php

namespace Tests\Feature\Orders;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use App\Models\PickupToken;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

/**
 * QR di ritiro: biglietto, scansione del venditore, ordini del giorno e chiusura di fine fascia.
 * Il negozio ritira dalle 17:00 alle 19:00 a Roma (15:00–17:00 UTC); si ordina fino alle 17:00 a Roma.
 */
class PickupTest extends TestCase
{
    use OrderHelpers, RefreshDatabase;

    private const ORDERED_AT = '2026-06-15 08:00:00';   // 10:00 a Roma

    private const PICKUP_OPEN = '2026-06-15 15:00:00';  // 17:00 a Roma

    private const PICKUP_CLOSE = '2026-06-15 17:00:00'; // 19:00 a Roma

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow(self::ORDERED_AT);
        $this->fakePayments();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un ordine pagato (e quindi con il suo biglietto), fatto la mattina. */
    private function paid(User $customer, Seller $shop, int $quantity = 1): array
    {
        $box = $this->makeBox($shop, ['stock_total' => 50, 'stock_available' => 50]);
        $order = $this->placeOrder($customer, [$box->id => $quantity]);
        $this->actingAsUser($customer)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk()->assertJsonPath('status', 'PAID');

        return $order;
    }

    /** Il biglietto come lo vede il cliente. */
    private function ticket(User $customer, int $orderId): array
    {
        return $this->actingAsUser($customer)->getJson("/api/orders/{$orderId}/pickup")->assertOk()->json();
    }

    /** Dopo un `actingAsUser` la sessione di prova resta attiva: per fare una richiesta senza accesso si azzera. */
    private function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function scan(User $seller, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($seller)->postJson('/api/seller/pickup/scan', $body);
    }

    // ---------- il biglietto del cliente ----------

    public function test_il_biglietto_nasce_al_pagamento_con_finestra_di_ritiro_e_codice(): void
    {
        $shop = $this->publishableShop();
        $order = $this->paid($this->customer(), $shop);

        $token = PickupToken::where('order_id', $order['id'])->firstOrFail();
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{8}$/', $token->short_code);
        $this->assertSame(40, strlen($token->jti));
        $this->assertSame(self::PICKUP_OPEN, $token->valid_from->toDateTimeString());
        $this->assertSame(self::PICKUP_CLOSE, $token->valid_until->toDateTimeString());
        $this->assertNull($token->used_at);
    }

    public function test_il_cliente_legge_qr_e_codice_e_sono_sempre_gli_stessi(): void
    {
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $this->publishableShop());

        $first = $this->ticket($anna, $order['id']);

        $this->assertStringStartsWith('TGQR1.', $first['qr']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $first['code']);
        $this->assertSame($order['number'], $first['number']);
        $this->assertSame('NOT_YET', $first['state']);                                 // sono le 10:00, il ritiro inizia alle 17:00
        $this->assertSame('2026-06-15T17:00:00+02:00', $first['valid_from']);
        $this->assertSame('2026-06-15T19:00:00+02:00', $first['valid_until']);
        $this->assertNull($first['used_at']);

        $this->assertSame($first, $this->ticket($anna, $order['id']));                  // non ne nasce un altro

        Carbon::setTestNow('2026-06-15 16:00:00');
        $this->assertSame('ACTIVE', $this->ticket($anna, $order['id'])['state']);
        Carbon::setTestNow('2026-06-15 17:00:01');
        $this->assertSame('EXPIRED', $this->ticket($anna, $order['id'])['state']);
    }

    public function test_il_qr_contiene_solo_ordine_e_identificativo_non_dati_personali(): void
    {
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $this->publishableShop());
        $qr = $this->ticket($anna, $order['id'])['qr'];

        [$prefix, $payload, $signature] = explode('.', $qr);
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        $this->assertSame('TGQR1', $prefix);
        $this->assertSame(['o', 'j'], array_keys($data));
        $this->assertSame($order['id'], $data['o']);
        $this->assertNotEmpty($signature);
        $this->assertStringNotContainsString($anna->email, $qr);
    }

    public function test_il_biglietto_c_e_solo_per_i_propri_ordini_pagati(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $box = $this->makeBox($shop);
        $pending = $this->placeOrder($anna, [$box->id => 1]);

        $this->actingAsUser($anna)->getJson("/api/orders/{$pending['id']}/pickup")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $paid = $this->paid($this->customer('luca'), $shop);
        $this->actingAsUser($anna)->getJson("/api/orders/{$paid['id']}/pickup")->assertNotFound();
        $this->actingAsUser($shop->user)->getJson("/api/orders/{$paid['id']}/pickup")->assertForbidden();
        $this->asGuest()->getJson("/api/orders/{$paid['id']}/pickup")->assertUnauthorized();
    }

    public function test_un_ordine_pagato_prima_della_funzione_riceve_il_biglietto_alla_prima_richiesta(): void
    {
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $this->publishableShop());
        PickupToken::where('order_id', $order['id'])->delete();

        $ticket = $this->ticket($anna, $order['id']);

        $this->assertDatabaseCount('pickup_tokens', 1);
        $this->assertSame(PickupToken::first()->short_code, str_replace('-', '', $ticket['code']));
    }

    public function test_l_email_di_conferma_riporta_il_codice_alternativo(): void
    {
        $order = $this->paid($this->customer('anna'), $this->publishableShop());
        $short = PickupToken::where('order_id', $order['id'])->value('short_code');
        $display = substr($short, 0, 4).'-'.substr($short, 4);

        Mail::assertSent(OrderConfirmationMail::class, fn ($mail) => str_contains($mail->render(), $display));
    }

    // ---------- la scansione ----------

    public function test_la_scansione_del_qr_conferma_il_ritiro(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop, 2);
        $ticket = $this->ticket($anna, $order['id']);

        Carbon::setTestNow('2026-06-15 16:00:00'); // 18:00 a Roma: ritiro in corso
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertOk()
            ->assertJsonPath('result', 'OK')
            ->assertJsonPath('order.number', $order['number'])
            ->assertJsonPath('order.status', 'PICKED_UP')
            ->assertJsonPath('order.customer.username', 'anna')
            ->assertJsonPath('order.units', 2)
            ->assertJsonPath('order.picked_up_at', '2026-06-15T16:00:00+00:00');

        $this->assertSame('PICKED_UP', $this->orderStatus($order['id']));
        $token = PickupToken::where('order_id', $order['id'])->first();
        $this->assertSame('2026-06-15 16:00:00', $token->used_at->toDateTimeString());
        $this->assertSame($shop->user->id, $token->scanned_by);

        // Il cliente vede il ritiro, e il biglietto risulta usato
        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('status', 'PICKED_UP')->assertJsonPath('picked_up_at', '2026-06-15T16:00:00+00:00');
        $this->assertSame('USED', $this->ticket($anna, $order['id'])['state']);
    }

    public function test_si_ritira_anche_con_il_codice_scritto_a_mano(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop);
        $code = $this->ticket($anna, $order['id'])['code'];

        Carbon::setTestNow('2026-06-15 16:00:00');
        // Minuscole, spazi e trattini in più non contano
        $typed = ' '.strtolower(substr($code, 0, 4)).' - '.strtolower(substr($code, 5)).' ';
        $this->scan($shop->user, ['code' => $typed])->assertOk()->assertJsonPath('order.status', 'PICKED_UP');
    }

    public function test_un_secondo_ritiro_con_lo_stesso_biglietto_e_rifiutato(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop);
        $ticket = $this->ticket($anna, $order['id']);

        Carbon::setTestNow('2026-06-15 16:00:00');
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertOk();

        Carbon::setTestNow('2026-06-15 16:05:00');
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_USED')->assertJsonPath('used_at', '2026-06-15T16:00:00+00:00');
        $this->scan($shop->user, ['code' => $ticket['code']])->assertStatus(409)->assertJsonPath('code', 'ALREADY_USED');

        $this->assertSame('2026-06-15 16:00:00', PickupToken::first()->used_at->toDateTimeString()); // la prima data resta
    }

    public function test_il_biglietto_di_un_altro_negozio_non_vale(): void
    {
        $mine = $this->publishableShop('Mio negozio');
        $other = $this->publishableShop('Altro negozio');
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $other);
        $ticket = $this->ticket($anna, $order['id']);

        Carbon::setTestNow('2026-06-15 16:00:00');
        $this->scan($mine->user, ['qr' => $ticket['qr']])->assertForbidden()->assertJsonPath('code', 'WRONG_SHOP');
        $this->scan($mine->user, ['code' => $ticket['code']])->assertForbidden()->assertJsonPath('code', 'WRONG_SHOP');

        // Un venditore senza attività non ritira nulla
        $nobody = User::factory()->seller()->create();
        $this->scan($nobody, ['qr' => $ticket['qr']])->assertForbidden()->assertJsonPath('code', 'WRONG_SHOP');

        $this->assertSame('PAID', $this->orderStatus($order['id']));
        $this->assertNull(PickupToken::first()->used_at);
    }

    public function test_qr_falsificati_o_inventati_sono_rifiutati(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop);
        $other = $this->paid($this->customer('luca'), $shop);
        $qr = $this->ticket($anna, $order['id'])['qr'];
        [$prefix, $payload, $signature] = explode('.', $qr);

        $otherQr = $this->ticket(User::where('username', 'luca')->first(), $other['id'])['qr'];
        [, $otherPayload] = explode('.', $otherQr);

        $forged = [
            'dati di un altro ordine con la firma di questo' => "{$prefix}.{$otherPayload}.{$signature}",
            'firma tolta' => "{$prefix}.{$payload}.",
            'firma inventata' => "{$prefix}.{$payload}.".str_repeat('A', strlen($signature)),
            'prefisso diverso' => "TGQR9.{$payload}.{$signature}",
            'pezzi in meno' => "{$prefix}.{$payload}",
            'testo qualsiasi' => 'https://esempio.it/ritiro?ordine=1',
            'dati modificati' => $prefix.'.'.rtrim(strtr(base64_encode(json_encode(['o' => $order['id'], 'j' => 'inventato'])), '+/', '-_'), '=').'.'.$signature,
        ];

        Carbon::setTestNow('2026-06-15 16:00:00');
        foreach ($forged as $why => $fake) {
            $this->scan($shop->user, ['qr' => $fake])->assertUnprocessable()->assertJsonPath('code', 'INVALID_QR', $why);
        }

        $this->scan($shop->user, ['code' => 'ZZZZ-9999'])->assertUnprocessable()->assertJsonPath('code', 'INVALID_CODE');
        $this->scan($shop->user, [])->assertUnprocessable()->assertJsonValidationErrors(['qr', 'code']);
        $this->assertSame(2, Order::where('status', 'PAID')->count()); // nessuno è stato ritirato
    }

    public function test_si_ritira_solo_nella_fascia_di_ritiro_con_i_bordi_inclusi(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop);
        $ticket = $this->ticket($anna, $order['id']);

        // 16:59:59 a Roma: il ritiro non è ancora iniziato
        Carbon::setTestNow('2026-06-15 14:59:59');
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertStatus(409)
            ->assertJsonPath('code', 'NOT_YET_VALID')->assertJsonPath('valid_from', '2026-06-15T17:00:00+02:00');

        // 19:00:01 a Roma: finito (il job di fine fascia non è ancora girato, ma il biglietto non vale più)
        Carbon::setTestNow('2026-06-15 17:00:01');
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertStatus(409)->assertJsonPath('code', 'EXPIRED');
        $this->assertSame('PAID', $this->orderStatus($order['id']));

        // Il minuto esatto di apertura e di chiusura sono validi
        Carbon::setTestNow(self::PICKUP_CLOSE);
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertOk();
    }

    public function test_il_giorno_dopo_il_biglietto_non_vale_piu(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop);
        $ticket = $this->ticket($anna, $order['id']);

        Carbon::setTestNow('2026-06-16 15:30:00'); // il giorno dopo, alla stessa ora di ritiro
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertStatus(409)->assertJsonPath('code', 'EXPIRED');
    }

    public function test_ordini_rimborsati_o_non_ritirati_non_si_ritirano(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $refunded = $this->paid($anna, $shop);
        $ticketRefunded = $this->ticket($anna, $refunded['id']);
        Order::whereKey($refunded['id'])->update(['status' => 'REFUNDED_FULL']);

        $closed = $this->paid($anna, $shop);
        $ticketClosed = $this->ticket($anna, $closed['id']);
        Order::whereKey($closed['id'])->update(['status' => Order::NOT_PICKED_UP]);

        Carbon::setTestNow('2026-06-15 16:00:00');
        $this->scan($shop->user, ['qr' => $ticketRefunded['qr']])->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_ACTIVE');
        $this->scan($shop->user, ['qr' => $ticketClosed['qr']])->assertStatus(409)->assertJsonPath('code', 'EXPIRED');
    }

    public function test_solo_i_venditori_scansionano_e_un_venditore_sospeso_ritira_comunque(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop);
        $ticket = $this->ticket($anna, $order['id']);
        Carbon::setTestNow('2026-06-15 16:00:00');

        $this->asGuest()->postJson('/api/seller/pickup/scan', ['qr' => $ticket['qr']])->assertUnauthorized();
        $this->actingAsUser($anna)->postJson('/api/seller/pickup/scan', ['qr' => $ticket['qr']])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');

        // Gli ordini già pagati si ritirano anche se il negozio è stato sospeso (US-S03 CA2)
        $shop->forceFill(['verification_status' => Seller::STATUS_SUSPENDED])->save();
        $shop->user->refresh();
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertOk()->assertJsonPath('order.status', 'PICKED_UP');
    }

    // ---------- ordini del giorno del venditore ----------

    public function test_il_venditore_vede_gli_ordini_del_giorno_con_lo_stato(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $luca = $this->customer('luca');
        $sara = $this->customer('sara');

        $toPick = $this->paid($anna, $shop, 2);
        $picked = $this->paid($luca, $shop, 1);
        $missed = $this->paid($sara, $shop, 3);
        Order::whereKey($missed['id'])->update(['status' => Order::NOT_PICKED_UP]);

        // Non compaiono: un ordine in attesa di pagamento, uno di un altro negozio e uno di ieri
        $box = $this->makeBox($shop, ['stock_total' => 50, 'stock_available' => 50]);
        $this->placeOrder($this->customer('marta'), [$box->id => 1]);
        $this->paid($this->customer('carlo'), $this->publishableShop('Altro'));
        $yesterday = $this->paid($this->customer('piero'), $shop);
        Order::whereKey($yesterday['id'])->update(['order_date' => '2026-06-14']);

        Carbon::setTestNow('2026-06-15 16:00:00'); // ritiro in corso
        $this->scan($shop->user, ['qr' => $this->ticket($luca, $picked['id'])['qr']])->assertOk();

        $response = $this->actingAsUser($shop->user)->getJson('/api/seller/orders')->assertOk()
            ->assertJsonPath('date', '2026-06-15')
            ->assertJsonPath('summary', ['to_pick_up' => 1, 'units_to_pick_up' => 2, 'picked_up' => 1, 'not_picked_up' => 1])
            ->assertJsonCount(3, 'data');

        $this->assertSame([$toPick['id'], $picked['id'], $missed['id']], array_column($response->json('data'), 'id'));
        $this->assertSame(['PAID', 'PICKED_UP', 'NOT_PICKED_UP'], array_column($response->json('data'), 'status'));
        $this->assertSame(['anna', 'luca', 'sara'], array_column(array_column($response->json('data'), 'customer'), 'username'));

        // Il giorno scelto
        $this->actingAsUser($shop->user)->getJson('/api/seller/orders?date=2026-06-14')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsUser($shop->user)->getJson('/api/seller/orders?date=ieri')->assertUnprocessable();
    }

    public function test_il_venditore_non_vede_i_dati_personali_dei_clienti(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $this->paid($anna, $shop);

        $body = json_encode($this->actingAsUser($shop->user)->getJson('/api/seller/orders')->assertOk()->json());

        $this->assertStringNotContainsString($anna->email, $body);
        $this->assertStringNotContainsString('qr', strtolower($body)); // nemmeno il codice di ritiro
        $this->assertStringNotContainsString(PickupToken::first()->short_code, $body);
    }

    public function test_gli_ordini_del_giorno_sono_solo_per_i_venditori_con_attivita(): void
    {
        $this->actingAsUser($this->customer())->getJson('/api/seller/orders')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
        $this->actingAsUser(User::factory()->seller()->create())->getJson('/api/seller/orders')->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
    }

    // ---------- fine fascia ----------

    public function test_a_fine_fascia_gli_ordini_non_ritirati_diventano_non_ritirati(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $luca = $this->customer('luca');
        $missed = $this->paid($anna, $shop);
        $picked = $this->paid($luca, $shop);

        // Un ordine ancora in attesa di pagamento non si tocca
        $box = $this->makeBox($shop, ['stock_total' => 50, 'stock_available' => 50]);
        $pending = $this->placeOrder($this->customer('marta'), [$box->id => 1]);

        Carbon::setTestNow('2026-06-15 16:00:00');
        $this->scan($shop->user, ['qr' => $this->ticket($luca, $picked['id'])['qr']])->assertOk();

        Carbon::setTestNow(self::PICKUP_CLOSE); // l'ultimo istante della fascia: ancora in corso
        $this->artisan('orders:close-pickup')->expectsOutput('Ordini non ritirati: 0')->assertSuccessful();
        $this->assertSame('PAID', $this->orderStatus($missed['id']));

        Carbon::setTestNow('2026-06-15 17:00:01');
        $this->artisan('orders:close-pickup')->expectsOutput('Ordini non ritirati: 1')->assertSuccessful();

        $this->assertSame('NOT_PICKED_UP', $this->orderStatus($missed['id']));
        $this->assertSame('PICKED_UP', $this->orderStatus($picked['id']));
        $this->assertSame('PENDING_PAYMENT', $this->orderStatus($pending['id']));

        // Rieseguirlo non cambia nulla
        $this->artisan('orders:close-pickup')->expectsOutput('Ordini non ritirati: 0')->assertSuccessful();
        $this->assertSame('NOT_PICKED_UP', $this->actingAsUser($anna)->getJson("/api/orders/{$missed['id']}")->json('status'));
    }

    public function test_un_ordine_non_ritirato_non_ha_piu_un_codice_utilizzabile(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paid($anna, $shop);
        $ticket = $this->ticket($anna, $order['id']);

        Carbon::setTestNow('2026-06-15 17:30:00');
        $this->artisan('orders:close-pickup')->assertSuccessful();

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}/pickup")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->scan($shop->user, ['qr' => $ticket['qr']])->assertStatus(409)->assertJsonPath('code', 'EXPIRED');
    }
}

<?php

namespace Tests\Feature\Orders;

use App\Mail\ComplaintDecisionMail;
use App\Mail\ComplaintOpenedMail;
use App\Models\Complaint;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Services\Stripe\TransferService;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\OrderHelpers;
use Tests\Concerns\PickupHelpers;
use Tests\TestCase;

/**
 * Reclami e rimborsi (US-C14, US-S05, US-V18).
 * Ritiro scansionato alle 16:00 UTC del 15 giugno: i casi b–d si aprono fino alle 16:00 del 16.
 * Ritiro 15:00–17:00 UTC: il caso a (non ritirato) si apre dalle 15:00 fino alle 17:00 del 16.
 * Il payout è dovuto il 16 alle 17:00 UTC; l'ordine da 7,00 € vale 5,50 € al venditore (tariffa 1,50 €).
 */
class ComplaintTest extends TestCase
{
    use OrderHelpers, PickupHelpers, RefreshDatabase;

    private const DUE = '2026-06-16 17:00:00';

    private FakeReversibleTransfers $transfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->fakePayments();
        Mail::fake();
        $this->transfers = new FakeReversibleTransfers;
        $this->app->instance(TransferService::class, $this->transfers);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function open(User $customer, int $orderId, string $reason = 'PLANTS_DAMAGED', string $text = 'Due piante erano secche.'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($customer)->postJson("/api/orders/{$orderId}/complaints", ['reason' => $reason, 'description' => $text]);
    }

    private function decide(int $complaintId, array $body, ?User $by = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($by ?? $this->admin())->postJson("/api/admin/complaints/{$complaintId}/decide", $body + ['note' => 'Valutato dopo le foto del cliente.']);
    }

    private function payout(int $orderId): Payout
    {
        return Payout::where('order_id', $orderId)->firstOrFail();
    }

    /** Ordine ritirato, 24 ore ancora aperte per il reclamo. Restituisce [cliente, negozio, ordine]. */
    private function pickedUp(string $name = 'anna'): array
    {
        $customer = $this->customer($name);
        $shop = $this->publishableShop();
        $order = $this->pickedUpOrder($customer, $shop);

        return [$customer, $shop, $order];
    }

    /** Reclamo già aperto su un ordine ritirato. */
    private function openedComplaint(): array
    {
        [$customer, $shop, $order] = $this->pickedUp();
        $id = $this->open($customer, $order['id'])->assertCreated()->json('id');

        return [$customer, $shop, $order, $id];
    }

    // ---------- apertura ----------

    public function test_il_cliente_apre_un_reclamo_e_l_ordine_si_ferma(): void
    {
        [$anna, $shop, $order] = $this->pickedUp();

        $this->open($anna, $order['id'], 'PLANTS_DAMAGED', 'Due piante erano secche.')
            ->assertCreated()->assertJsonPath('status', 'OPEN')->assertJsonPath('reason', 'PLANTS_DAMAGED')
            ->assertJsonPath('reason_label', 'Piante morte o danneggiate')->assertJsonPath('refund_cents', 0);

        $this->assertSame('IN_COMPLAINT', $this->orderStatus($order['id']));
        // Il pagamento al venditore aspetta la decisione
        $this->assertSame(Payout::HELD, $this->payout($order['id'])->status);
        Mail::assertSent(ComplaintOpenedMail::class, fn ($m) => $m->hasTo($shop->user->email));
    }

    public function test_i_motivi_validi_sono_i_quattro_casi_ammessi(): void
    {
        [$anna, , $order] = $this->pickedUp();

        $this->open($anna, $order['id'], 'NON_GRADITA')->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->open($anna, $order['id'], 'PLANTS_DAMAGED', 'corto')->assertUnprocessable()->assertJsonValidationErrors(['description']);
        $this->open($anna, $order['id'], 'PLANTS_DAMAGED', str_repeat('a', 2001))->assertUnprocessable()->assertJsonValidationErrors(['description']);
        $this->assertSame(0, Complaint::count());

        foreach (['VALUE_BELOW_DECLARED', 'NOT_AS_DECLARED', 'PLANTS_DAMAGED'] as $i => $reason) {
            // Un reclamo alla volta: si prova ogni motivo su un ordine nuovo
            [$c, , $o] = $this->pickedUp("cliente{$i}");
            $this->open($c, $o['id'], $reason)->assertCreated();
        }
    }

    public function test_dopo_il_ritiro_non_si_puo_dire_che_il_negozio_era_chiuso(): void
    {
        [$anna, , $order] = $this->pickedUp();

        $this->open($anna, $order['id'], 'CLOSED_OR_NOT_DELIVERED')->assertUnprocessable()->assertJsonPath('code', 'REASON_NOT_ALLOWED');
        $this->assertSame('PICKED_UP', $this->orderStatus($order['id']));
    }

    public function test_il_termine_dopo_il_ritiro_e_di_24_ore_precise(): void
    {
        [$anna, , $order] = $this->pickedUp();

        Carbon::setTestNow('2026-06-16 16:00:00');                      // esattamente 24 ore dopo: ancora valido
        $this->open($anna, $order['id'])->assertCreated();

        [$bruno, , $other] = $this->pickedUp('bruno');
        Carbon::setTestNow('2026-06-16 16:00:01');                      // un secondo dopo
        $this->open($bruno, $other['id'])->assertStatus(409)->assertJsonPath('code', 'COMPLAINT_WINDOW_CLOSED');
        $this->assertSame('PICKED_UP', $this->orderStatus($other['id']));
    }

    public function test_un_reclamo_solo_per_ordine(): void
    {
        [$anna, , $order] = $this->pickedUp();

        $this->open($anna, $order['id'])->assertCreated();
        $this->open($anna, $order['id'], 'NOT_AS_DECLARED')->assertStatus(409)->assertJsonPath('code', 'COMPLAINT_EXISTS');
        $this->assertSame(1, Complaint::count());
    }

    public function test_non_si_contesta_l_ordine_di_un_altro_ne_da_venditore(): void
    {
        [$anna, $shop, $order] = $this->pickedUp();

        $this->open($this->customer('bruno'), $order['id'])->assertNotFound();
        $this->open($shop->user, $order['id'])->assertForbidden();
        $this->assertSame(0, Complaint::count());
    }

    public function test_un_ordine_mai_pagato_non_si_contesta(): void
    {
        $anna = $this->customer();
        Carbon::setTestNow(self::ORDERED_AT);
        $box = $this->makeBox($this->publishableShop(), ['price_cents' => 700]);
        $order = $this->placeOrder($anna, [$box->id => 1]);

        $this->open($anna, $order['id'])->assertStatus(409)->assertJsonPath('code', 'COMPLAINT_NOT_ALLOWED');
    }

    // ---------- il caso a: negozio chiuso o box non consegnata ----------

    public function test_non_ritirato_si_contesta_solo_il_negozio_chiuso_a_ritiro_iniziato(): void
    {
        $anna = $this->customer();
        $shop = $this->publishableShop();
        $order = $this->paidOrder($anna, $shop);                     // 10:00 a Roma

        // Il ritiro non è ancora aperto
        $this->open($anna, $order['id'], 'CLOSED_OR_NOT_DELIVERED')->assertStatus(409)->assertJsonPath('code', 'COMPLAINT_NOT_ALLOWED');

        Carbon::setTestNow('2026-06-15 15:30:00');                  // ritiro in corso: il negozio è chiuso
        $this->open($anna, $order['id'], 'PLANTS_DAMAGED')->assertUnprocessable()->assertJsonPath('code', 'REASON_NOT_ALLOWED');
        $this->open($anna, $order['id'], 'CLOSED_OR_NOT_DELIVERED', 'Ho trovato la serranda abbassata.')->assertCreated();

        $this->assertSame('IN_COMPLAINT', $this->orderStatus($order['id']));
        // L'ordine contestato non diventa "non ritirato" a fine fascia
        Carbon::setTestNow('2026-06-15 17:05:00');
        $this->artisan('orders:close-pickup')->assertSuccessful();
        $this->assertSame('IN_COMPLAINT', $this->orderStatus($order['id']));
    }

    public function test_il_termine_del_caso_a_parte_dalla_fine_della_fascia(): void
    {
        $anna = $this->customer();
        $order = $this->paidOrder($anna, $this->publishableShop());
        Carbon::setTestNow('2026-06-15 17:05:00');
        $this->artisan('orders:close-pickup')->assertSuccessful();
        $this->assertSame('NOT_PICKED_UP', $this->orderStatus($order['id']));

        Carbon::setTestNow('2026-06-16 17:00:00');                  // fine fascia + 24 ore esatte
        $this->open($anna, $order['id'], 'CLOSED_OR_NOT_DELIVERED')->assertCreated()->assertJsonPath('status', 'OPEN');
    }

    public function test_il_caso_a_scade_24_ore_dopo_la_fine_della_fascia(): void
    {
        $anna = $this->customer();
        $order = $this->paidOrder($anna, $this->publishableShop());
        Carbon::setTestNow('2026-06-16 17:00:01');
        $this->artisan('orders:close-pickup')->assertSuccessful();

        $this->open($anna, $order['id'], 'CLOSED_OR_NOT_DELIVERED')->assertStatus(409)->assertJsonPath('code', 'COMPLAINT_WINDOW_CLOSED');
    }

    public function test_l_ordine_dice_quali_reclami_si_possono_aprire_e_fino_a_quando(): void
    {
        [$anna, , $order] = $this->pickedUp();

        $options = $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()->json('complaint_options');
        $this->assertSame(['VALUE_BELOW_DECLARED', 'PLANTS_DAMAGED', 'NOT_AS_DECLARED'], array_column($options['reasons'], 'code'));
        $this->assertSame('2026-06-16T16:00:00+00:00', $options['until']);

        Carbon::setTestNow('2026-06-16 16:00:01');
        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('complaint_options.reasons', [])->assertJsonPath('complaint_options.until', null);
    }

    public function test_il_cliente_vede_il_proprio_reclamo_e_l_esito(): void
    {
        [$anna, , $order, $id] = $this->openedComplaint();

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('status', 'IN_COMPLAINT')->assertJsonPath('complaint.status', 'OPEN')
            ->assertJsonPath('complaint_options.reasons', []);

        $this->decide($id, ['decision' => 'REJECT', 'note' => 'Le piante erano come dichiarato.'])->assertOk();

        $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}")->assertOk()
            ->assertJsonPath('status', 'COMPLAINT_REJECTED')->assertJsonPath('complaint.status', 'REJECTED')
            ->assertJsonPath('complaint.decision_note', 'Le piante erano come dichiarato.');
    }

    // ---------- decisione ----------

    public function test_rimborso_totale_prima_del_payout(): void
    {
        [$anna, $shop, $order, $id] = $this->openedComplaint();

        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertOk()
            ->assertJsonPath('status', 'ACCEPTED_FULL')->assertJsonPath('refund_cents', 700)->assertJsonPath('refunded_cents', 700);

        $this->assertSame('REFUNDED_FULL', $this->orderStatus($order['id']));
        // Rimborso su Stripe: intero importo, con una chiave legata al reclamo
        $this->assertSame([['pi_test_'.$order['id'], 700, "complaint-{$id}-refund"]], $this->payments->partialRefunds);
        $refund = Refund::where('order_id', $order['id'])->firstOrFail();
        $this->assertSame([700, $id, 're_complaint_1'], [$refund->amount_cents, $refund->complaint_id, $refund->stripe_refund_id]);
        $this->assertSame(Payout::SKIPPED, $this->payout($order['id'])->status);

        $complaint = Complaint::findOrFail($id);
        $this->assertNotNull($complaint->decided_at);
        $this->assertNotNull($complaint->decided_by);

        // Al venditore non va nulla
        Carbon::setTestNow(self::DUE);
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame([], $this->transfers->sent);

        Mail::assertSent(ComplaintDecisionMail::class, 2);
        Mail::assertSent(ComplaintDecisionMail::class, fn ($m) => $m->hasTo($anna->email) && ! $m->forSeller);
        Mail::assertSent(ComplaintDecisionMail::class, fn ($m) => $m->hasTo($shop->user->email) && $m->forSeller);
    }

    public function test_rimborso_parziale_riduce_il_payout(): void
    {
        [, , $order, $id] = $this->openedComplaint();

        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 200])->assertOk()
            ->assertJsonPath('status', 'ACCEPTED_PARTIAL')->assertJsonPath('refund_cents', 200);

        $this->assertSame('REFUNDED_PARTIAL', $this->orderStatus($order['id']));
        $this->assertSame([['pi_test_'.$order['id'], 200, "complaint-{$id}-refund"]], $this->payments->partialRefunds);
        $payout = $this->payout($order['id']);
        $this->assertSame([Payout::SCHEDULED, 350], [$payout->status, $payout->amount_cents]);   // 5,50 − 2,00

        Carbon::setTestNow(self::DUE);
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame([['acct_test', 350]], $this->transfers->sent);
    }

    public function test_un_rimborso_piu_grande_del_guadagno_azzera_il_payout(): void
    {
        [, , $order, $id] = $this->openedComplaint();

        // Il cliente riavrebbe 6,00 €; il venditore avrebbe incassato 5,50 €: il payout si ferma a zero, non diventa negativo
        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 600])->assertOk();

        $this->assertSame(0, $this->payout($order['id'])->amount_cents);
        Carbon::setTestNow(self::DUE);
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame([], $this->transfers->sent);
        $this->assertSame(Payout::SKIPPED, $this->payout($order['id'])->status);
    }

    public function test_rifiuto_riattiva_il_payout_intero(): void
    {
        [$anna, $shop, $order, $id] = $this->openedComplaint();

        $this->decide($id, ['decision' => 'REJECT', 'note' => 'Nessuna prova di danni.'])->assertOk()
            ->assertJsonPath('status', 'REJECTED')->assertJsonPath('refund_cents', 0);

        $this->assertSame('COMPLAINT_REJECTED', $this->orderStatus($order['id']));
        $this->assertSame([], $this->payments->partialRefunds);
        $this->assertSame(0, Refund::count());
        $this->assertSame(Payout::SCHEDULED, $this->payout($order['id'])->status);

        Carbon::setTestNow(self::DUE);
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame([['acct_test', 550]], $this->transfers->sent);

        Mail::assertSent(ComplaintDecisionMail::class, 2);
    }

    public function test_finche_il_reclamo_e_aperto_il_payout_non_parte(): void
    {
        [, , $order] = $this->openedComplaint();

        Carbon::setTestNow('2026-06-17 12:00:00');                  // molto dopo la scadenza
        $this->artisan('payouts:run')->assertSuccessful();

        $this->assertSame([], $this->transfers->sent);
        $this->assertSame(Payout::HELD, $this->payout($order['id'])->status);
    }

    public function test_rimborso_totale_dopo_il_payout_riprende_il_trasferimento(): void
    {
        [, , $order, $id] = $this->openedComplaint();
        // Caso raro (ritardo del payout abbreviato): il trasferimento era già partito quando il reclamo viene deciso
        DB::table('payouts')->where('order_id', $order['id'])->update(['status' => 'SENT', 'stripe_transfer_id' => 'tr_sent', 'sent_at' => now()]);

        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertOk();

        $this->assertSame([['tr_sent', 550, "complaint-{$id}-reversal"]], $this->transfers->reversed);
        $this->assertSame(Payout::REVERSED, $this->payout($order['id'])->status);
    }

    public function test_rimborso_parziale_dopo_il_payout_riprende_solo_la_differenza(): void
    {
        [, , $order, $id] = $this->openedComplaint();
        DB::table('payouts')->where('order_id', $order['id'])->update(['status' => 'SENT', 'stripe_transfer_id' => 'tr_sent', 'sent_at' => now()]);

        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 200])->assertOk();

        $this->assertSame([['tr_sent', 200, "complaint-{$id}-reversal"]], $this->transfers->reversed);
        $payout = $this->payout($order['id']);
        $this->assertSame([Payout::SENT, 350], [$payout->status, $payout->amount_cents]);
    }

    public function test_se_stripe_non_risponde_nulla_viene_salvato_e_si_puo_riprovare(): void
    {
        [, , $order, $id] = $this->openedComplaint();

        $this->payments->failRefund = true;
        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertStatus(502)->assertJsonPath('code', 'PAYMENT_PROVIDER_ERROR');

        $this->assertSame('OPEN', Complaint::findOrFail($id)->status);
        $this->assertSame('IN_COMPLAINT', $this->orderStatus($order['id']));
        $this->assertSame(Payout::HELD, $this->payout($order['id'])->status);
        $this->assertSame(0, Refund::count());
        Mail::assertNotSent(ComplaintDecisionMail::class);

        $this->payments->failRefund = false;
        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertOk();
        $this->assertSame('REFUNDED_FULL', $this->orderStatus($order['id']));
    }

    public function test_se_lo_storno_fallisce_la_decisione_non_si_salva(): void
    {
        [, , $order, $id] = $this->openedComplaint();
        DB::table('payouts')->where('order_id', $order['id'])->update(['status' => 'SENT', 'stripe_transfer_id' => 'tr_sent', 'sent_at' => now()]);

        $this->transfers->failReversal = true;
        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertStatus(502);

        $this->assertSame('OPEN', Complaint::findOrFail($id)->status);
        $this->assertSame(0, Refund::count());
        $this->assertSame(Payout::SENT, $this->payout($order['id'])->status);
    }

    public function test_un_reclamo_si_decide_una_volta_sola(): void
    {
        [, , $order, $id] = $this->openedComplaint();

        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertOk();
        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->decide($id, ['decision' => 'REJECT'])->assertStatus(409);

        $this->assertCount(1, $this->payments->partialRefunds);
        $this->assertSame(1, Refund::count());
        $this->assertSame('REFUNDED_FULL', $this->orderStatus($order['id']));
    }

    public function test_importi_di_rimborso_non_validi(): void
    {
        [, , $order, $id] = $this->openedComplaint();

        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL'])->assertUnprocessable()->assertJsonValidationErrors(['refund_cents']);
        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 0])->assertUnprocessable();
        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => -5])->assertUnprocessable();
        // Un rimborso parziale non può essere pari (o maggiore) al totale: per quello c'è il rimborso totale
        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 700])->assertUnprocessable()->assertJsonPath('code', 'INVALID_REFUND_AMOUNT');
        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 9999])->assertUnprocessable()->assertJsonPath('code', 'INVALID_REFUND_AMOUNT');
        $this->decide($id, ['decision' => 'BOH'])->assertUnprocessable()->assertJsonValidationErrors(['decision']);
        $this->decide($id, ['decision' => 'REJECT', 'note' => 'no'])->assertUnprocessable()->assertJsonValidationErrors(['note']);

        $this->assertSame([], $this->payments->partialRefunds);
        $this->assertSame('OPEN', Complaint::findOrFail($id)->status);
        $this->assertSame('IN_COMPLAINT', $this->orderStatus($order['id']));
    }

    // ---------- chi può fare cosa ----------

    public function test_solo_l_admin_gestisce_i_reclami(): void
    {
        [$anna, $shop, , $id] = $this->openedComplaint();

        foreach ([$anna, $shop->user] as $user) {
            $this->actingAsUser($user)->getJson('/api/admin/complaints')->assertForbidden();
            $this->actingAsUser($user)->getJson("/api/admin/complaints/{$id}")->assertForbidden();
            $this->decide($id, ['decision' => 'ACCEPT_FULL'], $user)->assertForbidden();
        }
        $this->assertSame('OPEN', Complaint::findOrFail($id)->status);
        $this->assertSame([], $this->payments->partialRefunds);
    }

    public function test_la_coda_dell_admin_ha_il_dettaglio_per_decidere(): void
    {
        [$anna, $shop, $order, $id] = $this->openedComplaint();
        $admin = $this->admin();

        $this->actingAsUser($admin)->getJson('/api/admin/complaints')->assertOk()
            ->assertJsonPath('open', 1)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.order.number', $order['number'])
            ->assertJsonPath('data.0.order.total_cents', 700)
            ->assertJsonPath('data.0.order.items.0.list_value_cents', 2000)
            ->assertJsonPath('data.0.shop.business_name', 'Vivaio Rossi')
            ->assertJsonPath('data.0.customer.email', $anna->email)
            ->assertJsonPath('data.0.payout.status', 'HELD')
            ->assertJsonPath('data.0.payout.amount_cents', 550);

        $this->actingAsUser($admin)->getJson("/api/admin/complaints/{$id}")->assertOk()->assertJsonPath('description', 'Due piante erano secche.');
        $this->actingAsUser($admin)->getJson('/api/admin/complaints/99999')->assertNotFound();

        $this->actingAsUser($admin)->getJson('/api/admin/complaints?status=DECIDED')->assertOk()->assertJsonCount(0, 'data');
        $this->decide($id, ['decision' => 'REJECT'], $admin)->assertOk();
        $this->actingAsUser($admin)->getJson('/api/admin/complaints')->assertOk()->assertJsonPath('open', 0)->assertJsonCount(0, 'data');
        $this->actingAsUser($admin)->getJson('/api/admin/complaints?status=DECIDED')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'REJECTED');
        $this->actingAsUser($admin)->getJson('/api/admin/complaints?status=BOH')->assertUnprocessable();
    }

    public function test_il_venditore_vede_i_reclami_dei_suoi_ordini_senza_dati_del_cliente(): void
    {
        [$anna, $shop, $order, $id] = $this->openedComplaint();
        $other = $this->publishableShop('Altro');

        $response = $this->actingAsUser($shop->user)->getJson('/api/seller/complaints')->assertOk()
            ->assertJsonPath('open', 1)->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.order.number', $order['number'])
            ->assertJsonPath('data.0.customer.username', 'anna')
            ->assertJsonPath('data.0.description', 'Due piante erano secche.');
        $this->assertStringNotContainsString($anna->email, $response->getContent());

        $this->actingAsUser($other->user)->getJson('/api/seller/complaints')->assertOk()->assertJsonPath('open', 0)->assertJsonCount(0, 'data');
        $this->actingAsUser($anna)->getJson('/api/seller/complaints')->assertForbidden();

        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 300, 'note' => 'Due piante da sostituire.'])->assertOk();
        $this->actingAsUser($shop->user)->getJson('/api/seller/complaints')->assertOk()
            ->assertJsonPath('open', 0)->assertJsonPath('data.0.status', 'ACCEPTED_PARTIAL')
            ->assertJsonPath('data.0.refund_cents', 300)->assertJsonPath('data.0.decision_note', 'Due piante da sostituire.');
    }

    public function test_gli_incassi_mostrano_l_importo_sospeso_e_il_rimborso(): void
    {
        [, $shop, , $id] = $this->openedComplaint();

        $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk()
            ->assertJsonPath('summary.held_cents', 550)->assertJsonPath('data.0.payout_status', 'HELD');

        $this->decide($id, ['decision' => 'ACCEPT_PARTIAL', 'refund_cents' => 200])->assertOk();
        $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk()
            ->assertJsonPath('summary.held_cents', 0)->assertJsonPath('summary.pending_cents', 350)
            ->assertJsonPath('data.0.refunded_cents', 200)->assertJsonPath('data.0.earning_cents', 350)
            ->assertJsonPath('data.0.order_status', 'REFUNDED_PARTIAL');
    }

    public function test_un_rimborso_totale_esce_dagli_incassi(): void
    {
        [, $shop, , $id] = $this->openedComplaint();

        $this->decide($id, ['decision' => 'ACCEPT_FULL'])->assertOk();

        $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk()
            ->assertJsonPath('summary.orders', 0)->assertJsonCount(0, 'data');
    }

    public function test_il_payout_arrivato_a_scadenza_con_reclamo_aperto_resta_sospeso_anche_se_non_ancora_toccato(): void
    {
        // Reclamo del caso a (negozio chiuso): il termine coincide con il payout
        $anna = $this->customer();
        $order = $this->paidOrder($anna, $this->publishableShop());
        Carbon::setTestNow('2026-06-16 16:59:00');
        $this->artisan('orders:close-pickup')->assertSuccessful();
        $this->open($anna, $order['id'], 'CLOSED_OR_NOT_DELIVERED')->assertCreated();

        Carbon::setTestNow(self::DUE);
        $this->artisan('payouts:run')->assertSuccessful();
        $this->assertSame([], $this->transfers->sent);
        $this->assertSame(Payout::HELD, $this->payout($order['id'])->status);
    }
}

/** Trasferimenti finti: registrano invio e storno e possono far fallire lo storno. */
class FakeReversibleTransfers extends TransferService
{
    /** @var list<array{string, int}> */
    public array $sent = [];

    /** @var list<array{string, int, string}> */
    public array $reversed = [];

    public bool $failReversal = false;

    public function send($payout, string $accountId): string
    {
        $this->sent[] = [$accountId, $payout->amount_cents];

        return 'tr_test_'.$payout->id;
    }

    public function reverse($payout, int $cents, string $idempotencyKey): string
    {
        if ($this->failReversal) {
            throw new RuntimeException('Saldo del venditore insufficiente');
        }
        $this->reversed[] = [$payout->stripe_transfer_id, $cents, $idempotencyKey];

        return 'trr_test_'.count($this->reversed);
    }
}

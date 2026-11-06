<?php

namespace Tests\Feature\Seller;

use App\Mail\SellerSuspensionMail;
use App\Models\Complaint;
use App\Models\Payout;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\Concerns\PickupHelpers;
use Tests\TestCase;

/**
 * Sospensione dei venditori (US-S03, US-V18 CA1, US-S05 CA2).
 * Ritiro scansionato alle 16:00 UTC del 15 giugno; i reclami si decidono lo stesso giorno.
 */
class SuspensionTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function suspend(Seller $shop, string $note = 'Box non conformi più volte.'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($this->admin())->postJson("/api/admin/sellers/{$shop->id}/suspend", ['note' => $note]);
    }

    private function sellerStatus(Seller $shop): string
    {
        return (string) Seller::whereKey($shop->id)->value('verification_status');
    }

    /** Un ordine ritirato e un reclamo aperto su di esso. Restituisce l'id del reclamo. */
    private function complaintOn(Seller $shop, string $customerName): int
    {
        $customer = $this->customer($customerName);
        $order = $this->pickedUpOrder($customer, $shop);

        $this->actingAsUser($customer)->postJson("/api/orders/{$order['id']}/complaints", [
            'reason' => 'PLANTS_DAMAGED', 'description' => 'Le piante erano secche.',
        ])->assertCreated();

        return (int) Complaint::where('order_id', $order['id'])->value('id');
    }

    private function decide(int $complaintId, string $decision = 'ACCEPT_FULL', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAsUser($this->admin())->postJson("/api/admin/complaints/{$complaintId}/decide", [
            'decision' => $decision, 'note' => 'Valutato dopo le foto del cliente.',
        ] + $extra);
    }

    // ---------- sospensione e riattivazione dall'admin ----------

    public function test_l_admin_sospende_un_venditore_approvato_e_lui_riceve_l_email(): void
    {
        $shop = $this->publishableShop();

        $this->suspend($shop)->assertOk()
            ->assertJsonPath('status', 'SUSPENDED')
            ->assertJsonPath('verification_note', 'Box non conformi più volte.');

        $this->assertSame('SUSPENDED', $this->sellerStatus($shop));
        Mail::assertSent(SellerSuspensionMail::class, fn ($m) => $m->hasTo($shop->user->email) && $m->suspended
            && str_contains($m->render(), 'Box non conformi più volte.'));
    }

    public function test_la_sospensione_vuole_un_motivo_e_solo_per_i_venditori_approvati(): void
    {
        $shop = $this->publishableShop();
        $admin = $this->admin();

        $this->actingAsUser($admin)->postJson("/api/admin/sellers/{$shop->id}/suspend", ['note' => 'no'])->assertUnprocessable();
        $this->actingAsUser($admin)->postJson("/api/admin/sellers/{$shop->id}/suspend", [])->assertUnprocessable();
        $this->assertSame('APPROVED', $this->sellerStatus($shop));

        $pending = $this->sellerWithProfile(Seller::STATUS_PENDING, vat: 'IT33333333333');
        $this->suspend($pending)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');

        $this->suspend($shop)->assertOk();
        $this->suspend($shop)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE'); // già sospeso
    }

    public function test_solo_l_admin_sospende_e_riattiva(): void
    {
        $shop = $this->publishableShop();

        $this->actingAsUser($shop->user)->postJson("/api/admin/sellers/{$shop->id}/suspend", ['note' => 'Prova di sospensione.'])->assertForbidden();
        $this->actingAsUser($this->customer())->postJson("/api/admin/sellers/{$shop->id}/reactivate")->assertForbidden();
        $this->assertSame('APPROVED', $this->sellerStatus($shop));
    }

    public function test_da_sospeso_il_negozio_sparisce_non_pubblica_e_non_riceve_ordini(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop);
        $anna = $this->customer('anna');

        $this->getJson("/api/shops/{$shop->id}")->assertOk();
        $this->suspend($shop)->assertOk();

        $this->getJson("/api/shops/{$shop->id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/shops?include_empty=1')->json('data'));

        $this->actingAsUser($anna)->postJson('/api/orders', $this->cart([$box->id => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'SHOP_NOT_AVAILABLE');
        $this->assertSame(5, $this->stockOf($box));

        $this->actingAsUser($shop->user)->postJson('/api/seller/boxes', ['box_type' => 'MEDIUM'])
            ->assertForbidden()->assertJsonPath('code', 'SELLER_NOT_READY');
        // Vede comunque il proprio stato e il motivo
        $this->actingAsUser($shop->user)->getJson('/api/seller')->assertOk()
            ->assertJsonPath('status', 'SUSPENDED')->assertJsonPath('can_publish', false)
            ->assertJsonPath('verification_note', 'Box non conformi più volte.');
    }

    public function test_gli_ordini_gia_pagati_si_ritirano_e_il_payout_resta_programmato(): void
    {
        $shop = $this->publishableShop();
        $anna = $this->customer('anna');
        $order = $this->paidOrder($anna, $shop);
        $ticket = $this->actingAsUser($anna)->getJson("/api/orders/{$order['id']}/pickup")->assertOk()->json();

        $this->suspend($shop)->assertOk();

        Carbon::setTestNow(self::SCAN_AT);
        $this->actingAsUser($shop->user)->postJson('/api/seller/pickup/scan', ['qr' => $ticket['qr']])
            ->assertOk()->assertJsonPath('order.status', 'PICKED_UP');
        $this->assertSame('SCHEDULED', Payout::where('order_id', $order['id'])->value('status'));
        $this->actingAsUser($shop->user)->getJson('/api/seller/earnings')->assertOk();
    }

    public function test_riattivato_il_negozio_torna_in_vendita(): void
    {
        $shop = $this->publishableShop();
        $box = $this->makeBox($shop);
        $admin = $this->admin();

        $this->actingAsUser($admin)->postJson("/api/admin/sellers/{$shop->id}/reactivate")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE'); // non è sospeso

        $this->suspend($shop)->assertOk();
        $this->actingAsUser($admin)->postJson("/api/admin/sellers/{$shop->id}/reactivate")
            ->assertOk()->assertJsonPath('status', 'APPROVED')->assertJsonPath('verification_note', null);

        $this->getJson("/api/shops/{$shop->id}")->assertOk();
        $this->actingAsUser($this->customer())->postJson('/api/orders', $this->cart([$box->id => 1]))->assertCreated();
        Mail::assertSent(SellerSuspensionMail::class, fn ($m) => ! $m->suspended);
    }

    public function test_i_sospesi_compaiono_nella_coda_dell_admin(): void
    {
        $shop = $this->publishableShop();
        $this->suspend($shop)->assertOk();

        $this->actingAsUser($this->admin())->getJson('/api/admin/sellers?status=SUSPENDED')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shop->id);
    }

    // ---------- reclami ripetuti ----------

    public function test_tre_reclami_accolti_sospendono_il_venditore_da_soli(): void
    {
        $shop = $this->publishableShop();

        $first = $this->complaintOn($shop, 'anna');
        $second = $this->complaintOn($shop, 'luca');
        $third = $this->complaintOn($shop, 'sara');

        $this->decide($first)->assertOk();
        $this->decide($second, 'ACCEPT_PARTIAL', ['refund_cents' => 200])->assertOk();
        $this->assertSame('APPROVED', $this->sellerStatus($shop));        // due non bastano

        $this->decide($third)->assertOk();
        $this->assertSame('SUSPENDED', $this->sellerStatus($shop));
        $this->assertStringContainsString('3 reclami accolti', (string) Seller::whereKey($shop->id)->value('verification_note'));
        Mail::assertSent(SellerSuspensionMail::class, fn ($m) => $m->hasTo($shop->user->email) && $m->suspended);
    }

    public function test_i_reclami_respinti_non_contano(): void
    {
        $shop = $this->publishableShop();

        foreach (['anna', 'luca', 'sara', 'gino'] as $name) {
            $this->decide($this->complaintOn($shop, $name), 'REJECT')->assertOk();
        }

        $this->assertSame('APPROVED', $this->sellerStatus($shop));
        Mail::assertNotSent(SellerSuspensionMail::class);
    }

    public function test_i_reclami_vecchi_non_contano(): void
    {
        $shop = $this->publishableShop();
        $first = $this->complaintOn($shop, 'anna');
        $second = $this->complaintOn($shop, 'luca');
        $third = $this->complaintOn($shop, 'sara');
        $this->decide($first)->assertOk();
        $this->decide($second)->assertOk();
        // Il primo è stato deciso 40 giorni fa: fuori dai 30 della finestra
        DB::table('complaints')->where('id', $first)->update(['decided_at' => Carbon::now()->subDays(40)]);

        $this->decide($third)->assertOk();

        $this->assertSame('APPROVED', $this->sellerStatus($shop));
    }

    public function test_la_soglia_e_un_parametro_e_zero_la_spegne(): void
    {
        $shop = $this->publishableShop();
        DB::table('config_params')->where('key', 'suspend_after_complaints')->update(['value' => '1']);
        $this->decide($this->complaintOn($shop, 'anna'))->assertOk();
        $this->assertSame('SUSPENDED', $this->sellerStatus($shop));

        $other = $this->publishableShop('Vivaio Verdi');
        DB::table('config_params')->where('key', 'suspend_after_complaints')->update(['value' => '0']);
        foreach (['anna2', 'luca2', 'sara2', 'gino2'] as $name) {
            $this->decide($this->complaintOn($other, $name))->assertOk();
        }
        $this->assertSame('APPROVED', $this->sellerStatus($other));
    }

    public function test_l_admin_puo_sospendere_insieme_alla_decisione(): void
    {
        $shop = $this->publishableShop();
        $id = $this->complaintOn($shop, 'anna');

        $this->decide($id, 'ACCEPT_FULL', ['suspend_seller' => true])->assertOk()->assertJsonPath('status', 'ACCEPTED_FULL');

        $this->assertSame('SUSPENDED', $this->sellerStatus($shop));
        $this->assertStringContainsString('Valutato dopo le foto', (string) Seller::whereKey($shop->id)->value('verification_note'));
        // Il rimborso è comunque partito
        $this->assertCount(1, $this->payments->partialRefunds);
    }

    public function test_senza_il_parametro_nel_database_la_soglia_predefinita_vale_comunque(): void
    {
        DB::table('config_params')->whereIn('key', ['suspend_after_complaints', 'suspend_window_days'])->delete();
        $shop = $this->publishableShop();

        foreach (['anna', 'luca', 'sara'] as $name) {
            $this->decide($this->complaintOn($shop, $name))->assertOk();
        }

        $this->assertSame('SUSPENDED', $this->sellerStatus($shop));
    }
}

<?php

namespace Tests\Feature\Seller;

use App\Mail\SellerVerificationMail;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SellerHelpers;
use Tests\TestCase;

class AdminVerificationTest extends TestCase
{
    use RefreshDatabase, SellerHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeExternalServices();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function addDocument(Seller $seller, string $name = 'visura.pdf'): void
    {
        $this->actingAsUser($seller->user)
            ->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create($name, 10, 'application/pdf')])
            ->assertCreated();
    }

    public function test_solo_l_admin_accede_alla_coda(): void
    {
        $seller = $this->sellerWithProfile();

        $this->actingAsUser($seller->user)->getJson('/api/admin/sellers')->assertForbidden();
        $this->actingAsUser(User::factory()->create())->getJson('/api/admin/sellers')->assertForbidden();
        $this->actingAsUser($this->admin())->getJson('/api/admin/sellers')->assertOk();
    }

    public function test_l_admin_con_la_password_iniziale_non_puo_ancora_verificare(): void
    {
        $this->actingAsUser(User::factory()->admin()->create(['must_change_password' => true]))
            ->getJson('/api/admin/sellers')
            ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_la_coda_mostra_le_richieste_in_attesa_con_l_esito_dei_controlli(): void
    {
        $pending = $this->sellerWithProfile(Seller::STATUS_PENDING, vat: 'IT22222222222');
        $pending->forceFill(['vat_check_status' => Seller::VAT_UNAVAILABLE])->save();
        $this->sellerWithProfile(Seller::STATUS_APPROVED, vat: 'IT12345678901');

        $this->actingAsUser($this->admin())
            ->getJson('/api/admin/sellers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.vat_check_status', 'UNAVAILABLE')
            ->assertJsonPath('data.0.email', $pending->user->email);

        $this->getJson('/api/admin/sellers?status=APPROVED')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/sellers?status=BOH')->assertUnprocessable();
    }

    public function test_approvare_aggiorna_lo_stato_e_avvisa_il_venditore(): void
    {
        $seller = $this->sellerWithProfile();
        $this->addDocument($seller);

        $this->actingAsUser($this->admin())
            ->postJson("/api/admin/sellers/{$seller->id}/approve")
            ->assertOk()->assertJsonPath('status', 'APPROVED');

        $this->assertNotNull($seller->fresh()->verified_at);
        Mail::assertSent(SellerVerificationMail::class, fn ($m) => $m->hasTo($seller->user->email) && $m->approved);
    }

    public function test_senza_documenti_non_si_approva(): void
    {
        $seller = $this->sellerWithProfile();

        $this->actingAsUser($this->admin())
            ->postJson("/api/admin/sellers/{$seller->id}/approve")
            ->assertUnprocessable()->assertJsonPath('code', 'NO_DOCUMENTS');
        $this->assertSame(Seller::STATUS_PENDING, $seller->fresh()->verification_status);
    }

    public function test_rifiutare_richiede_una_motivazione_che_il_venditore_vede(): void
    {
        $seller = $this->sellerWithProfile();
        $admin = $this->actingAsUser($this->admin());

        $admin->postJson("/api/admin/sellers/{$seller->id}/reject")->assertUnprocessable()->assertJsonValidationErrors('note');

        $admin->postJson("/api/admin/sellers/{$seller->id}/reject", ['note' => 'Il documento non è leggibile'])
            ->assertOk()->assertJsonPath('status', 'REJECTED');
        Mail::assertSent(SellerVerificationMail::class, fn ($m) => ! $m->approved && $m->note === 'Il documento non è leggibile');

        $this->actingAsUser($seller->user)->getJson('/api/seller')
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonPath('verification_note', 'Il documento non è leggibile')
            ->assertJsonPath('editable', true);
    }

    public function test_si_decide_una_sola_volta(): void
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_APPROVED);
        $admin = $this->actingAsUser($this->admin());

        $admin->postJson("/api/admin/sellers/{$seller->id}/approve")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $admin->postJson("/api/admin/sellers/{$seller->id}/reject", ['note' => 'Tardi per rifiutare'])->assertStatus(409);
        $this->assertSame(Seller::STATUS_APPROVED, $seller->fresh()->verification_status);
    }

    public function test_solo_l_admin_scarica_i_documenti_e_solo_quelli_del_venditore_indicato(): void
    {
        $seller = $this->sellerWithProfile();
        $this->addDocument($seller);
        $other = $this->sellerWithProfile(vat: 'IT12345678901');
        $this->addDocument($other, 'altro.pdf');
        $doc = $seller->documents()->first();
        $otherDoc = $other->documents()->first();

        $this->actingAsUser($seller->user)->get("/api/admin/sellers/{$seller->id}/documents/{$doc->id}")->assertForbidden();

        $this->actingAsUser($this->admin())
            ->get("/api/admin/sellers/{$seller->id}/documents/{$doc->id}")
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("/api/admin/sellers/{$seller->id}/documents/{$otherDoc->id}")->assertNotFound();
    }

    public function test_percorso_completo_da_in_verifica_ad_approvato_e_pronto_a_pubblicare(): void
    {
        $user = User::factory()->seller()->create();

        $this->actingAsUser($user)->putJson('/api/seller/profile', $this->profilePayload())->assertCreated();
        $this->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('visura.pdf', 10, 'application/pdf')])->assertCreated();
        $this->getJson('/api/seller')->assertJsonPath('status', 'PENDING')->assertJsonPath('can_publish', false);

        $seller = $user->fresh()->seller;
        $this->actingAsUser($this->admin())->postJson("/api/admin/sellers/{$seller->id}/approve")->assertOk();

        $this->actingAsUser($user)->getJson('/api/seller')
            ->assertJsonPath('status', 'APPROVED')->assertJsonPath('can_publish', false);

        $seller->forceFill(['stripe_onboarded_at' => now()])->save();
        $this->getJson('/api/seller')->assertJsonPath('can_publish', true);
    }
}

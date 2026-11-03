<?php

namespace Tests\Feature\Seller;

use App\Models\Seller;
use App\Models\User;
use App\Services\Stripe\ConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Stripe\Exception\ApiConnectionException;
use Tests\Concerns\SellerHelpers;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase, SellerHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeExternalServices();
    }

    public function test_un_venditore_nuovo_vede_l_onboarding_incompleto(): void
    {
        $this->actingAsUser(User::factory()->seller()->create())
            ->getJson('/api/seller')
            ->assertOk()
            ->assertJsonPath('status', 'INCOMPLETE')
            ->assertJsonPath('can_publish', false);
    }

    public function test_un_cliente_non_puo_usare_le_rotte_del_venditore(): void
    {
        $this->actingAsUser(User::factory()->create())
            ->getJson('/api/seller')
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_senza_accesso_le_rotte_rispondono_401(): void
    {
        $this->getJson('/api/seller')->assertUnauthorized();
    }

    public function test_il_primo_invio_crea_il_profilo_in_verifica_con_coordinate_e_piva_verificata(): void
    {
        $this->actingAsUser($user = User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload())
            ->assertCreated()
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('seller.city', 'Milano')
            ->assertJsonPath('seller.latitude', 45.4642)
            ->assertJsonPath('seller.pickup_from', '17:00')
            ->assertJsonPath('can_publish', false);

        $seller = $user->seller;
        $this->assertSame(Seller::VAT_VALID, $seller->vat_check_status);
        $this->assertSame(Seller::STATUS_PENDING, $seller->verification_status);
    }

    public function test_la_partita_iva_senza_prefisso_o_con_spazi_viene_normalizzata(): void
    {
        $this->actingAsUser(User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload(['vat_number' => '123 456 789 01']))
            ->assertCreated()
            ->assertJsonPath('seller.vat_number', 'IT12345678901');
    }

    public function test_una_partita_iva_con_formato_sbagliato_e_rifiutata(): void
    {
        $this->actingAsUser(User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload(['vat_number' => 'IT123']))
            ->assertUnprocessable()->assertJsonValidationErrors('vat_number');
    }

    public function test_una_partita_iva_inesistente_e_rifiutata_e_non_salva_nulla(): void
    {
        $this->actingAsUser($user = User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload(['vat_number' => 'IT55555555555']))
            ->assertUnprocessable()->assertJsonPath('code', 'VAT_INVALID');

        $this->assertNull($user->fresh()->seller);
    }

    public function test_con_il_servizio_piva_giu_il_profilo_si_salva_ma_il_controllo_resta_non_verificato(): void
    {
        $this->actingAsUser($user = User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload(['vat_number' => 'IT99999999999']))
            ->assertCreated();

        $this->assertSame(Seller::VAT_UNAVAILABLE, $user->seller->vat_check_status);
    }

    public function test_una_partita_iva_gia_usata_da_un_altro_venditore_e_rifiutata(): void
    {
        $this->sellerWithProfile(vat: 'IT12345678901');

        $this->actingAsUser(User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload())
            ->assertUnprocessable()->assertJsonValidationErrors('vat_number');
    }

    public function test_un_indirizzo_non_trovato_e_rifiutato(): void
    {
        $this->actingAsUser(User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload(['address' => 'Via Inesistente 9, Atlantide']))
            ->assertUnprocessable()->assertJsonPath('code', 'ADDRESS_NOT_FOUND');
    }

    public function test_con_il_geocoding_giu_risponde_503_senza_salvare(): void
    {
        $this->fakeExternalServices(geocodingDown: true);

        $this->actingAsUser($user = User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload())
            ->assertStatus(503)->assertJsonPath('code', 'GEOCODING_UNAVAILABLE');

        $this->assertNull($user->fresh()->seller);
    }

    public function test_gli_orari_incoerenti_sono_rifiutati(): void
    {
        $user = User::factory()->seller()->create();

        foreach ([
            ['opens_at' => '19:00', 'closes_at' => '08:00'],      // chiude prima di aprire
            ['pickup_from' => '07:00'],                            // ritiro prima dell'apertura
            ['pickup_to' => '20:00'],                              // ritiro dopo la chiusura
            ['pickup_from' => '18:00', 'pickup_to' => '17:00'],    // fascia al contrario
            ['opens_at' => '8 del mattino'],                       // formato errato
        ] as $override) {
            $this->actingAsUser($user)
                ->putJson('/api/seller/profile', $this->profilePayload($override))
                ->assertUnprocessable();
        }
    }

    public function test_riinviare_gli_stessi_dati_non_richiama_i_servizi_esterni(): void
    {
        $this->actingAsUser(User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload())->assertCreated();
        Http::assertSentCount(2);

        $this->putJson('/api/seller/profile', $this->profilePayload(['phone' => '+39 333 1234567']))
            ->assertOk()->assertJsonPath('seller.phone', '+39 333 1234567');
        Http::assertSentCount(2);
    }

    public function test_un_profilo_approvato_non_si_modifica(): void
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_APPROVED);

        $this->actingAsUser($seller->user)
            ->putJson('/api/seller/profile', $this->profilePayload(['business_name' => 'Altro nome']))
            ->assertForbidden()->assertJsonPath('code', 'PROFILE_LOCKED');
    }

    public function test_il_venditore_non_puo_scegliere_il_proprio_stato(): void
    {
        $this->actingAsUser($user = User::factory()->seller()->create())
            ->putJson('/api/seller/profile', $this->profilePayload([
                'verification_status' => 'APPROVED', 'stripe_account_id' => 'acct_x', 'user_id' => 999,
            ]))
            ->assertCreated();

        $this->assertSame(Seller::STATUS_PENDING, $user->seller->verification_status);
        $this->assertNull($user->seller->stripe_account_id);
        $this->assertSame($user->id, $user->seller->user_id);
    }

    // --- Documenti ---

    public function test_si_puo_caricare_un_documento_pdf_e_resta_in_storage_privato(): void
    {
        $seller = $this->sellerWithProfile();

        $this->actingAsUser($seller->user)
            ->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('visura.pdf', 200, 'application/pdf')])
            ->assertCreated()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.name', 'visura.pdf')
            ->assertJsonMissingPath('documents.0.storage_key');

        $document = $seller->documents()->first();
        $this->assertStringStartsWith("seller-documents/{$seller->id}/", $document->storage_key);
        Storage::disk('s3')->assertExists($document->storage_key);
    }

    public function test_i_formati_non_ammessi_e_i_file_troppo_grandi_sono_rifiutati(): void
    {
        $seller = $this->sellerWithProfile();
        $this->actingAsUser($seller->user);

        $this->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('nota.txt', 10, 'text/plain')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('enorme.pdf', 6000, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, $seller->documents()->count());
    }

    public function test_un_file_rinominato_con_estensione_pdf_ma_contenuto_diverso_e_rifiutato(): void
    {
        $seller = $this->sellerWithProfile();
        // File vero su disco (non il finto di Laravel, che si fida del nome): il tipo si legge dal contenuto
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, "<?php echo 'ciao';");
        $fake = new UploadedFile($path, 'falso.pdf', 'application/pdf', null, true);

        $this->actingAsUser($seller->user)
            ->postJson('/api/seller/documents', ['file' => $fake])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_non_si_caricano_piu_di_cinque_documenti(): void
    {
        $seller = $this->sellerWithProfile();
        $this->actingAsUser($seller->user);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create("d$i.pdf", 10, 'application/pdf')])->assertCreated();
        }

        $this->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('d6.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonPath('code', 'TOO_MANY_DOCUMENTS');
    }

    public function test_il_documento_richiede_prima_il_profilo(): void
    {
        $this->actingAsUser(User::factory()->seller()->create())
            ->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('d.pdf', 10, 'application/pdf')])
            ->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
    }

    public function test_si_elimina_un_proprio_documento_ma_non_quello_di_un_altro(): void
    {
        $mine = $this->sellerWithProfile();
        $other = $this->sellerWithProfile(vat: 'IT12345678901');
        $this->actingAsUser($mine->user)
            ->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('mio.pdf', 10, 'application/pdf')]);
        $this->actingAsUser($other->user)
            ->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('altro.pdf', 10, 'application/pdf')]);
        $myDoc = $mine->documents()->first();
        $otherDoc = $other->documents()->first();

        $this->actingAsUser($mine->user)->deleteJson("/api/seller/documents/{$otherDoc->id}")->assertNotFound();
        $this->deleteJson("/api/seller/documents/{$myDoc->id}")->assertOk()->assertJsonCount(0, 'documents');
        Storage::disk('s3')->assertMissing($myDoc->storage_key);
        Storage::disk('s3')->assertExists($otherDoc->storage_key);
    }

    // --- Reinvio dopo un rifiuto ---

    public function test_dopo_un_rifiuto_si_puo_reinviare_la_richiesta_con_un_documento(): void
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_REJECTED);
        $seller->forceFill(['verification_note' => 'Documento illeggibile'])->save();
        $this->actingAsUser($seller->user);

        $this->postJson('/api/seller/resubmit')->assertUnprocessable()->assertJsonPath('code', 'NO_DOCUMENTS');

        $this->postJson('/api/seller/documents', ['file' => UploadedFile::fake()->create('nuovo.pdf', 10, 'application/pdf')])->assertCreated();
        $this->postJson('/api/seller/resubmit')
            ->assertOk()->assertJsonPath('status', 'PENDING')->assertJsonPath('verification_note', null);
    }

    public function test_reinviare_senza_un_rifiuto_non_ha_senso(): void
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_PENDING);

        $this->actingAsUser($seller->user)
            ->postJson('/api/seller/resubmit')
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    // --- Stripe Connect ---

    public function test_l_onboarding_stripe_crea_l_account_una_volta_sola_e_restituisce_il_link(): void
    {
        $seller = $this->sellerWithProfile();
        $this->mock(ConnectService::class, function ($mock) {
            $mock->shouldReceive('createAccount')->once()->andReturn('acct_123');
            $mock->shouldReceive('onboardingUrl')->twice()->with('acct_123')->andReturn('https://connect.stripe.test/setup/abc');
        });

        $this->actingAsUser($seller->user)->postJson('/api/seller/stripe/onboarding')
            ->assertOk()->assertJsonPath('url', 'https://connect.stripe.test/setup/abc');
        $this->postJson('/api/seller/stripe/onboarding')->assertOk();

        $this->assertSame('acct_123', $seller->fresh()->stripe_account_id);
    }

    public function test_senza_profilo_non_si_collega_stripe(): void
    {
        $this->actingAsUser(User::factory()->seller()->create())
            ->postJson('/api/seller/stripe/onboarding')
            ->assertStatus(409)->assertJsonPath('code', 'PROFILE_REQUIRED');
    }

    public function test_con_stripe_giu_risponde_502(): void
    {
        $seller = $this->sellerWithProfile();
        $this->mock(ConnectService::class, function ($mock) {
            $mock->shouldReceive('createAccount')->andThrow(new ApiConnectionException('rete'));
        });

        $this->actingAsUser($seller->user)->postJson('/api/seller/stripe/onboarding')
            ->assertStatus(502)->assertJsonPath('code', 'STRIPE_UNAVAILABLE');
        $this->assertNull($seller->fresh()->stripe_account_id);
    }

    public function test_la_sincronizzazione_segna_l_onboarding_completo_solo_se_stripe_conferma(): void
    {
        $seller = $this->sellerWithProfile();
        $seller->forceFill(['stripe_account_id' => 'acct_123'])->save();
        $this->mock(ConnectService::class, function ($mock) {
            $mock->shouldReceive('isOnboarded')->with('acct_123')->andReturn(false, true);
        });

        $this->actingAsUser($seller->user)->postJson('/api/seller/stripe/sync')
            ->assertOk()->assertJsonPath('stripe_connected', false);
        $this->postJson('/api/seller/stripe/sync')
            ->assertOk()->assertJsonPath('stripe_connected', true);
    }

    public function test_si_puo_pubblicare_solo_se_approvato_e_con_stripe_collegato(): void
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_APPROVED);
        $this->actingAsUser($seller->user)->getJson('/api/seller')->assertJsonPath('can_publish', false);

        $seller->forceFill(['stripe_onboarded_at' => now()])->save();
        $this->getJson('/api/seller')->assertJsonPath('can_publish', true);

        $seller->forceFill(['verification_status' => Seller::STATUS_SUSPENDED])->save();
        $this->getJson('/api/seller')->assertJsonPath('can_publish', false);
    }
}

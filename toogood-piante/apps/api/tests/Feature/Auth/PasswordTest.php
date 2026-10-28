<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AuthHelpers;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_il_recupero_risponde_sempre_allo_stesso_modo(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
        $this->postJson('/api/auth/password/forgot', ['email' => 'nessuno@example.com'])->assertStatus(202);

        Mail::assertSent(PasswordResetCodeMail::class, 1);
    }

    public function test_con_il_codice_si_reimposta_la_password_e_si_chiudono_le_sessioni(): void
    {
        $user = User::factory()->create();
        $old = $this->loginWithCode($user);

        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertStatus(202);
        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email,
            'code' => $this->lastResetCode(),
            'password' => 'Nuova-Password-1',
            'password_confirmation' => 'Nuova-Password-1',
        ])->assertOk();

        // sessioni precedenti chiuse
        $this->bearer($old['access_token'])->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $old['refresh_token']])->assertUnauthorized();

        // la vecchia password non vale più, la nuova sì
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->loginWithCode($user->fresh(), 'Nuova-Password-1');
    }

    public function test_il_codice_di_recupero_vale_una_volta_sola(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);
        $payload = ['email' => $user->email, 'code' => $this->lastResetCode(), 'password' => 'Nuova-Password-1', 'password_confirmation' => 'Nuova-Password-1'];

        $this->postJson('/api/auth/password/reset', $payload)->assertOk();
        $this->postJson('/api/auth/password/reset', $payload)->assertUnprocessable()->assertJsonPath('code', 'INVALID_RESET_CODE');
    }

    public function test_un_codice_di_recupero_sbagliato_viene_rifiutato_e_dopo_cinque_si_blocca(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);
        $right = $this->lastResetCode();
        $wrong = $right === '000000' ? '111111' : '000000';
        $payload = fn (string $code) => ['email' => $user->email, 'code' => $code, 'password' => 'Nuova-Password-1', 'password_confirmation' => 'Nuova-Password-1'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/password/reset', $payload($wrong))->assertUnprocessable();
        }

        // Passato il minuto del limite di velocità, il codice resta comunque bloccato dai tentativi falliti
        $this->travel(2)->minutes();
        $this->postJson('/api/auth/password/reset', $payload($right))->assertUnprocessable();
        $this->assertTrue(password_verify('password', $user->fresh()->password));
    }

    public function test_il_codice_di_recupero_scade_dopo_trenta_minuti(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);
        $code = $this->lastResetCode();

        $this->travel(31)->minutes();

        $this->postJson('/api/auth/password/reset', ['email' => $user->email, 'code' => $code, 'password' => 'Nuova-Password-1', 'password_confirmation' => 'Nuova-Password-1'])
            ->assertUnprocessable();
    }

    public function test_la_nuova_password_deve_rispettare_le_regole(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email]);

        $this->postJson('/api/auth/password/reset', ['email' => $user->email, 'code' => $this->lastResetCode(), 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_cambio_password_richiede_quella_attuale(): void
    {
        $tokens = $this->loginWithCode(User::factory()->create());
        $new = ['password' => 'Nuova-Password-1', 'password_confirmation' => 'Nuova-Password-1'];

        $this->bearer($tokens['access_token'])
            ->postJson('/api/auth/password/change', ['current_password' => 'sbagliata'] + $new)
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_CURRENT_PASSWORD');
    }

    public function test_cambio_password_chiude_le_altre_sessioni_e_mantiene_questa(): void
    {
        $user = User::factory()->create();
        $phone = $this->loginWithCode($user);
        $tablet = $this->loginWithCode($user);

        $response = $this->bearer($phone['access_token'])
            ->postJson('/api/auth/password/change', ['current_password' => 'password', 'password' => 'Nuova-Password-1', 'password_confirmation' => 'Nuova-Password-1'])
            ->assertOk()->assertJsonStructure(['refresh_token']);

        $this->bearer($phone['access_token'])->getJson('/api/me')->assertOk();
        $this->bearer($tablet['access_token'])->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $tablet['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $response->json('refresh_token')])->assertOk();
    }

    public function test_la_nuova_password_deve_essere_diversa_dall_attuale(): void
    {
        $tokens = $this->loginWithCode(User::factory()->create());

        $this->bearer($tokens['access_token'])
            ->postJson('/api/auth/password/change', ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertUnprocessable();
    }

    public function test_il_seeder_crea_l_admin_con_obbligo_di_cambiare_la_password(): void
    {
        config(['toogood.admin.email' => 'Capo@Example.com', 'toogood.admin.initial_password' => 'Iniziale-Pass-1']);

        $this->seed(AdminSeeder::class);
        $this->seed(AdminSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $admin = User::first();
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertSame('capo@example.com', $admin->email);
        $this->assertTrue($admin->must_change_password);
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_l_admin_con_password_iniziale_puo_solo_cambiarla(): void
    {
        Route::middleware(['auth:sanctum', 'active', 'password.fresh'])->get('/api/test-protetta', fn () => ['ok' => true]);

        config(['toogood.admin.email' => 'capo@example.com', 'toogood.admin.initial_password' => 'Iniziale-Pass-1']);
        $this->seed(AdminSeeder::class);
        $admin = User::first();

        $tokens = $this->loginWithCode($admin, 'Iniziale-Pass-1');
        $this->assertTrue($tokens['user']['must_change_password']);

        $this->bearer($tokens['access_token'])->getJson('/api/me')->assertOk();
        $this->bearer($tokens['access_token'])->getJson('/api/test-protetta')
            ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->bearer($tokens['access_token'])
            ->postJson('/api/auth/password/change', ['current_password' => 'Iniziale-Pass-1', 'password' => 'Definitiva-Pass-2', 'password_confirmation' => 'Definitiva-Pass-2'])
            ->assertOk();

        $this->bearer($tokens['access_token'])->getJson('/api/test-protetta')->assertOk();
        $this->assertFalse($admin->fresh()->must_change_password);
    }
}

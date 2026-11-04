<?php

namespace Tests\Feature\Auth;

use App\Mail\LoginCodeMail;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AuthHelpers;
use Tests\TestCase;

class TrustedDeviceTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** Accesso con codice chiedendo di ricordare il dispositivo; restituisce il token del dispositivo. */
    private function loginAndRemember(User $user): ?string
    {
        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('requires_code', true)->json('challenge_id');

        return $this->postJson('/api/auth/login/verify', [
            'challenge_id' => $challenge,
            'code' => $this->lastLoginCode(),
            'remember_device' => true,
        ])->assertOk()->json('device_token');
    }

    public function test_senza_richiesta_il_dispositivo_non_viene_ricordato(): void
    {
        $user = User::factory()->create();
        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('challenge_id');

        $this->postJson('/api/auth/login/verify', ['challenge_id' => $challenge, 'code' => $this->lastLoginCode()])
            ->assertOk()->assertJsonMissingPath('device_token');

        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_dispositivo_ricordato_entra_senza_nuovo_codice(): void
    {
        $user = User::factory()->create();
        $deviceToken = $this->loginAndRemember($user);
        $this->assertNotEmpty($deviceToken);
        $this->assertDatabaseMissing('trusted_devices', ['token_hash' => $deviceToken]); // nel database c'è solo l'hash

        Mail::fake(); // azzera le email già inviate

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_token' => $deviceToken,
        ])->assertOk()
            ->assertJsonPath('requires_code', false)
            ->assertJsonStructure(['access_token', 'refresh_token', 'user' => ['email']]);

        Mail::assertNotSent(LoginCodeMail::class);
    }

    public function test_la_password_resta_obbligatoria_anche_su_un_dispositivo_ricordato(): void
    {
        $user = User::factory()->create();
        $deviceToken = $this->loginAndRemember($user);

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'sbagliata', 'device_token' => $deviceToken,
        ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_il_token_di_un_altro_utente_non_salta_il_codice(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceDevice = $this->loginAndRemember($alice);

        $this->postJson('/api/auth/login', [
            'email' => $bob->email, 'password' => 'password', 'device_token' => $aliceDevice,
        ])->assertOk()->assertJsonPath('requires_code', true)->assertJsonStructure(['challenge_id']);
    }

    public function test_token_inventato_o_scaduto_richiede_il_codice(): void
    {
        $user = User::factory()->create();
        $deviceToken = $this->loginAndRemember($user);

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_token' => 'inventato',
        ])->assertOk()->assertJsonPath('requires_code', true);

        TrustedDevice::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password', 'device_token' => $deviceToken,
        ])->assertOk()->assertJsonPath('requires_code', true);
    }

    public function test_il_cambio_password_annulla_i_dispositivi_ricordati(): void
    {
        $user = User::factory()->create();
        $deviceToken = $this->loginAndRemember($user);
        $tokens = $this->loginWithCode($user);

        $this->bearer($tokens['access_token'])->postJson('/api/auth/password/change', [
            'current_password' => 'password',
            'password' => 'Nuova-password-123',
            'password_confirmation' => 'Nuova-password-123',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'Nuova-password-123', 'device_token' => $deviceToken,
        ])->assertOk()->assertJsonPath('requires_code', true);
    }

    public function test_per_l_admin_il_codice_e_sempre_richiesto(): void
    {
        $admin = User::factory()->create();
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        $challenge = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])->json('challenge_id');
        $this->postJson('/api/auth/login/verify', [
            'challenge_id' => $challenge, 'code' => $this->lastLoginCode(), 'remember_device' => true,
        ])->assertOk()->assertJsonMissingPath('device_token');

        // Anche con un token valido inserito a mano nel database, l'admin deve inserire il codice
        TrustedDevice::create([
            'user_id' => $admin->id, 'token_hash' => hash('sha256', 'forzato'),
            'expires_at' => now()->addDay(),
        ]);
        $this->postJson('/api/auth/login', [
            'email' => $admin->email, 'password' => 'password', 'device_token' => 'forzato',
        ])->assertOk()->assertJsonPath('requires_code', true);
    }
}

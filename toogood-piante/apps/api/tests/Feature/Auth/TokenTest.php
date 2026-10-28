<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AuthHelpers;
use Tests\TestCase;

class TokenTest extends TestCase
{
    use AuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_il_refresh_token_viene_ruotato_a_ogni_uso(): void
    {
        $tokens = $this->loginWithCode(User::factory()->create());

        $new = $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk()->json();

        $this->assertNotSame($tokens['refresh_token'], $new['refresh_token']);
        $this->assertNotSame($tokens['access_token'], $new['access_token']);
        $this->bearer($new['access_token'])->getJson('/api/me')->assertOk();
    }

    public function test_riusare_un_refresh_token_vecchio_chiude_tutte_le_sessioni(): void
    {
        $tokens = $this->loginWithCode(User::factory()->create());
        $new = $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->json();

        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized()->assertJsonPath('code', 'REFRESH_TOKEN_REUSED');

        // Anche il token nuovo e l'access token non valgono più
        $this->postJson('/api/auth/refresh', ['refresh_token' => $new['refresh_token']])->assertUnauthorized();
        $this->bearer($new['access_token'])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_un_refresh_token_inventato_viene_rifiutato(): void
    {
        $this->postJson('/api/auth/refresh', ['refresh_token' => str_repeat('a', 64)])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_REFRESH_TOKEN');
    }

    public function test_un_refresh_token_scaduto_viene_rifiutato(): void
    {
        $tokens = $this->loginWithCode(User::factory()->create());

        $this->travel(31)->days();

        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_l_access_token_scade_dopo_quindici_minuti(): void
    {
        $tokens = $this->loginWithCode(User::factory()->create());

        $this->travel(14)->minutes();
        $this->bearer($tokens['access_token'])->getJson('/api/me')->assertOk();

        $this->travel(2)->minutes();
        $this->bearer($tokens['access_token'])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_il_logout_invalida_l_access_token_e_il_refresh_token(): void
    {
        $tokens = $this->loginWithCode(User::factory()->create());

        $this->bearer($tokens['access_token'])
            ->postJson('/api/auth/logout', ['refresh_token' => $tokens['refresh_token']])
            ->assertNoContent();

        $this->bearer($tokens['access_token'])->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_il_logout_chiude_solo_questo_dispositivo(): void
    {
        $user = User::factory()->create();
        $phone = $this->loginWithCode($user);
        $tablet = $this->loginWithCode($user);

        $this->bearer($phone['access_token'])->postJson('/api/auth/logout', ['refresh_token' => $phone['refresh_token']])->assertNoContent();

        $this->bearer($tablet['access_token'])->getJson('/api/me')->assertOk();
    }

    public function test_ripresentare_il_token_di_un_logout_non_chiude_le_altre_sessioni(): void
    {
        $user = User::factory()->create();
        $phone = $this->loginWithCode($user);
        $tablet = $this->loginWithCode($user);

        $this->bearer($phone['access_token'])->postJson('/api/auth/logout', ['refresh_token' => $phone['refresh_token']])->assertNoContent();

        $this->postJson('/api/auth/refresh', ['refresh_token' => $phone['refresh_token']])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_REFRESH_TOKEN');

        $this->postJson('/api/auth/refresh', ['refresh_token' => $tablet['refresh_token']])->assertOk();
    }

    public function test_un_account_sospeso_dopo_l_accesso_viene_bloccato(): void
    {
        $user = User::factory()->create();
        $tokens = $this->loginWithCode($user);

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->bearer($tokens['access_token'])->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_NOT_ACTIVE');
        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertForbidden();
    }
}

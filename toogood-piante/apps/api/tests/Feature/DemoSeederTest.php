<?php

namespace Tests\Feature;

use App\Models\BoxOffer;
use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\OrderHelpers;
use Tests\TestCase;

/** Dati di prova per la demo (giorno 13). */
class DemoSeederTest extends TestCase
{
    use OrderHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        Carbon::setTestNow('2026-06-15 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_crea_clienti_negozi_e_box_di_oggi_e_si_puo_rilanciare(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(2, User::where('role', 'CUSTOMER')->count());
        $this->assertSame(4, Seller::count());
        $this->assertSame(6, BoxOffer::count());
        $this->assertSame(1, Seller::where('verification_status', 'SUSPENDED')->count());
        $this->assertNotNull(User::where('email', 'anna.demo@toogood.test')->value('email_verified_at'));
    }

    public function test_i_negozi_demo_compaiono_nella_ricerca_tranne_il_sospeso(): void
    {
        $this->seed(DemoSeeder::class);

        $names = collect($this->getJson('/api/shops')->assertOk()->json('data'))->pluck('business_name')->all();
        sort($names);

        $this->assertSame(['Piante & Co', 'Verde Milano', 'Vivaio Rossi'], $names);
    }

    public function test_si_compra_da_un_negozio_demo_con_le_credenziali_della_demo(): void
    {
        Mail::fake();
        $this->fakePayments();
        $this->seed(DemoSeeder::class);

        $login = $this->postJson('/api/auth/login', ['email' => 'anna.demo@toogood.test', 'password' => DemoSeeder::PASSWORD]);
        $login->assertOk(); // password giusta: parte il codice via email

        $box = BoxOffer::whereHas('seller', fn ($q) => $q->where('business_name', 'Vivaio Rossi'))->firstOrFail();
        $anna = User::where('email', 'anna.demo@toogood.test')->firstOrFail();
        $this->actingAsUser($anna)->postJson('/api/orders', $this->cart([$box->id => 1]))->assertCreated();
    }

    public function test_non_gira_in_produzione(): void
    {
        $this->app['env'] = 'production';

        app(DemoSeeder::class)->run();

        $this->assertSame(0, User::count());
    }
}

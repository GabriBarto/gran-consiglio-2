<?php

namespace Tests\Concerns;

use App\Models\BoxOffer;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use App\Services\Stripe\PaymentService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Negozi, box, clienti e un Stripe finto per i test degli ordini. */
trait OrderHelpers
{
    use SellerHelpers;

    protected FakePayments $payments;

    private int $vatCounter = 20000000000;

    /** Sostituisce Stripe con una copia che registra le chiamate (nessuna richiesta di rete). */
    protected function fakePayments(): FakePayments
    {
        $this->payments = new FakePayments;
        $this->app->instance(PaymentService::class, $this->payments);

        return $this->payments;
    }

    /** Un negozio che può vendere: approvato e con il conto collegato. Ritiro 17:00–19:00, ordini fino alle 17:00. */
    protected function publishableShop(string $name = 'Vivaio Rossi'): Seller
    {
        $seller = $this->sellerWithProfile(Seller::STATUS_APPROVED, vat: 'IT'.++$this->vatCounter);
        $seller->forceFill(['business_name' => $name, 'stripe_account_id' => 'acct_test', 'stripe_onboarded_at' => now()])->save();

        return $seller;
    }

    protected function makeBox(Seller $seller, array $overrides = []): BoxOffer
    {
        $box = new BoxOffer;
        $box->seller_id = $seller->id;
        $box->offer_date = $overrides['offer_date'] ?? app(Clock::class)->todayLocal()->toDateString();
        $box->box_type_id = DB::table('box_types')->where('code', 'MEDIUM')->value('id');
        $box->category_id = DB::table('categories')->where('code', 'SUCCULENTS')->value('id');
        $box->setPlantAttributes(['INDOOR']);
        $box->description = $overrides['description'] ?? 'Tre piante grasse';
        $box->list_value_cents = $overrides['list_value_cents'] ?? 2000;
        $box->discount_pct = 65;
        $box->price_cents = $overrides['price_cents'] ?? 700;
        $box->fee_cents = 150;
        $box->stock_total = $overrides['stock_total'] ?? 5;
        $box->stock_available = $overrides['stock_available'] ?? 5;
        $box->status = $overrides['status'] ?? BoxOffer::STATUS_ACTIVE;
        $box->save();

        return $box;
    }

    protected function customer(string $username = 'anna'): User
    {
        return User::factory()->create(['username' => $username]);
    }

    /** @param array<int, int> $quantities box_id => quantità */
    protected function cart(array $quantities, bool $accept = true): array
    {
        $items = [];
        foreach ($quantities as $boxId => $quantity) {
            $items[] = ['box_id' => $boxId, 'quantity' => $quantity];
        }

        return ['items' => $items, 'accept_no_refund' => $accept];
    }

    /** Crea l'ordine dal punto di vista del cliente e restituisce la risposta JSON. */
    protected function placeOrder(User $customer, array $quantities): array
    {
        return $this->actingAsUser($customer)->postJson('/api/orders', $this->cart($quantities))->assertCreated()->json();
    }

    protected function stockOf(BoxOffer $box): int
    {
        return (int) BoxOffer::whereKey($box->id)->value('stock_available');
    }

    protected function orderStatus(int $orderId): string
    {
        return (string) Order::whereKey($orderId)->value('status');
    }
}

/** Stripe finto: registra le chiamate e può essere fatto fallire. */
class FakePayments extends PaymentService
{
    /** @var list<int> id degli ordini per cui è stato creato un PaymentIntent */
    public array $intents = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<string> */
    public array $refunded = [];

    public bool $failCreate = false;

    public bool $failRefund = false;

    public function createIntent($order): array
    {
        if ($this->failCreate) {
            throw new RuntimeException('Stripe non risponde');
        }
        $this->intents[] = $order->id;

        return ['id' => 'pi_test_'.$order->id, 'client_secret' => 'pi_test_'.$order->id.'_secret_x'];
    }

    public function cancelIntent(string $intentId): void
    {
        $this->cancelled[] = $intentId;
    }

    public function refund(string $intentId): string
    {
        if ($this->failRefund) {
            throw new RuntimeException('Stripe non risponde');
        }
        $this->refunded[] = $intentId;

        return 're_test';
    }
}

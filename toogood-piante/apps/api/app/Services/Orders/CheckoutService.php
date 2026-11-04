<?php

namespace App\Services\Orders;

use App\Models\BoxOffer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seller;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Boxes\BusinessConfig;
use App\Services\Boxes\OrderWindow;
use App\Services\Stripe\PaymentService;
use App\Support\ApiError;
use App\Support\Clock;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dal carrello all'ordine in attesa di pagamento (US-C04…C07):
 * controlla negozio, orario limite e stock, tiene da parte le box per qualche minuto, crea l'ordine e il PaymentIntent.
 *
 * Il carrello vive nell'app: qui arriva già pronto e viene sempre ricontrollato, perché nel frattempo
 * lo stock, il prezzo o l'orario possono essere cambiati.
 */
class CheckoutService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BusinessConfig $config,
        private readonly OrderWindow $window,
        private readonly OrderLifecycle $lifecycle,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  list<array{box_id: int, quantity: int}>  $items
     * @return array{order: Order, intent: array{id: string, client_secret: string}}
     */
    public function checkout(User $customer, array $items): array
    {
        $cancelOnStripe = [];

        // Con molti acquisti simultanei MySQL può chiudere una transazione per deadlock (errore 1213): è un esito
        // normale e innocuo, e Laravel la ripete da capo fino a 5 volte. Per questo la lista si azzera a ogni tentativo.
        $order = DB::transaction(function () use ($customer, $items, &$cancelOnStripe) {
            $cancelOnStripe = [];

            // Un cliente ha un solo ordine in attesa: rifare il carrello libera quello precedente,
            // così provare più volte non toglie stock agli altri.
            // Niente `lockForUpdate` su questa ricerca: bloccherebbe un intervallo dell'indice e farebbe
            // incrociare acquisti di clienti diversi. Basta il blocco della singola riga in releaseLocked(),
            // che ricontrolla lo stato: due richieste dello stesso cliente non liberano mai due volte.
            foreach (Order::where('customer_id', $customer->id)->where('status', Order::PENDING_PAYMENT)->pluck('id') as $previousId) {
                $result = $this->lifecycle->releaseLocked($previousId, Order::EXPIRED);
                if ($result['released'] && $result['intent']) {
                    $cancelOnStripe[] = $result['intent'];
                }
            }

            return $this->createOrder($customer, $items);
        }, 5);

        foreach ($cancelOnStripe as $intentId) {
            $this->lifecycle->cancelIntentQuietly($intentId);
        }

        try {
            $intent = $this->payments->createIntent($order);
        } catch (Throwable) {
            // Stripe non risponde: nessun addebito possibile, quindi le box tornano subito disponibili
            $this->lifecycle->release($order, Order::PAYMENT_FAILED);
            ApiError::throw('STRIPE_UNAVAILABLE', 'Il servizio di pagamento non risponde. Riprova tra poco.', 502);
        }

        $order->stripe_payment_intent_id = $intent['id'];
        $order->save();

        return ['order' => $order->load(['items.box', 'seller', 'reservations']), 'intent' => $intent];
    }

    /** @param list<array{box_id: int, quantity: int}> $items */
    private function createOrder(User $customer, array $items): Order
    {
        $quantities = [];
        foreach ($items as $item) {
            $quantities[(int) $item['box_id']] = (int) $item['quantity'];
        }
        ksort($quantities); // sempre nello stesso ordine: due acquisti simultanei non si bloccano a vicenda

        // Righe delle box bloccate fino alla fine della transazione: il controllo dello stock e il suo calo sono un passo unico
        $boxes = BoxOffer::whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($boxes->count() !== count($quantities)) {
            ApiError::throw('BOX_NOT_FOUND', 'Una delle box non esiste più.', 404);
        }

        $today = $this->clock->todayLocal()->toDateString();
        foreach ($boxes as $box) {
            if ($box->offer_date->toDateString() !== $today || $box->status !== BoxOffer::STATUS_ACTIVE) {
                ApiError::throw('BOX_NOT_AVAILABLE', 'Una delle box non è più in vendita.', 409);
            }
        }

        if ($boxes->pluck('seller_id')->unique()->count() !== 1) {
            ApiError::throw('MIXED_SHOPS', 'Un ordine può contenere box di un solo negozio.', 422);
        }

        $seller = Seller::findOrFail($boxes->first()->seller_id);
        if (! $seller->canPublish()) {
            ApiError::throw('SHOP_NOT_AVAILABLE', 'Questo negozio non può ricevere ordini in questo momento.', 409);
        }

        if (! $this->window->isOpen($seller)) {
            $deadline = $this->window->deadline($seller)->format('H:i');
            ApiError::throw('ORDER_DEADLINE_PASSED', "L'orario limite per ordinare da questo negozio era alle {$deadline}.", 409);
        }

        $shortages = [];
        foreach ($quantities as $boxId => $quantity) {
            if ($boxes[$boxId]->stock_available < $quantity) {
                $shortages[] = ['box_id' => $boxId, 'requested' => $quantity, 'available' => $boxes[$boxId]->stock_available];
            }
        }
        if ($shortages) {
            // Nessun addebito e nessuna box tenuta da parte: la transazione si annulla
            throw new HttpResponseException(response()->json([
                'code' => 'OUT_OF_STOCK',
                'message' => 'Alcune box non sono più disponibili nella quantità richiesta.',
                'items' => $shortages,
            ], 409));
        }

        $total = 0;
        $feeTotal = 0;
        foreach ($quantities as $boxId => $quantity) {
            $total += $boxes[$boxId]->price_cents * $quantity;
            $feeTotal += $boxes[$boxId]->fee_cents * $quantity;
        }

        $minimum = (int) config('toogood.payments.min_amount_cents');
        if ($total < $minimum) {
            ApiError::throw('PAYMENT_AMOUNT_TOO_LOW', 'Il totale è troppo basso per essere pagato con carta (minimo '.number_format($minimum / 100, 2, ',', '').' €).', 422);
        }

        $now = $this->clock->now();

        $order = new Order;
        $order->customer_id = $customer->id;
        $order->seller_id = $seller->id;
        $order->order_date = $today;
        $order->status = Order::PENDING_PAYMENT;
        $order->total_cents = $total;
        $order->fee_total_cents = $feeTotal;
        $order->no_refund_accepted_at = $now;
        $order->pickup_window_end_at = $this->clock->todayLocal()->setTimeFromTimeString((string) $seller->pickup_to)->utc();
        $order->save();

        $expires = $now->addMinutes($this->config->int('reservation_minutes'));

        foreach ($quantities as $boxId => $quantity) {
            $box = $boxes[$boxId];

            $item = new OrderItem;
            $item->order_id = $order->id;
            $item->box_offer_id = $boxId;
            $item->quantity = $quantity;
            $item->unit_price_cents = $box->price_cents;   // istantanee: una modifica successiva non cambia l'ordine
            $item->unit_fee_cents = $box->fee_cents;
            $item->save();

            $reservation = new StockReservation;
            $reservation->order_id = $order->id;
            $reservation->box_offer_id = $boxId;
            $reservation->quantity = $quantity;
            $reservation->expires_at = $expires;
            $reservation->save();

            $box->stock_available -= $quantity;
            $box->save();
        }

        return $order;
    }
}

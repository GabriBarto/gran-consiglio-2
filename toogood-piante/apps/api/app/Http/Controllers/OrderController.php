<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\CheckoutService;
use App\Services\Orders\PaymentEventHandler;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Ordini del cliente: checkout con stock riservato e pagamento (US-C04…C07). Solo ruolo CUSTOMER. */
class OrderController extends Controller
{
    /**
     * Trasforma il carrello in un ordine in attesa di pagamento: tiene da parte le box per qualche minuto
     * (parametro `reservation_minutes`) e crea il PaymentIntent. L'app completa il pagamento direttamente con Stripe
     * usando `payment.client_secret`; il risultato arriva al server con il webhook.
     */
    public function store(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.box_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
            // Casella obbligatoria: "se non ritiri la box non è previsto rimborso" (RB-09, US-C06)
            'accept_no_refund' => ['required', 'accepted'],
        ]);

        $result = $checkout->checkout($request->user(), $data['items']);

        return response()->json(OrderResource::make($result['order']) + [
            'payment' => [
                'client_secret' => $result['intent']['client_secret'],
                'payment_intent_id' => $result['intent']['id'],
                'publishable_key' => config('toogood.stripe.publishable_key'),
                // In sviluppo, con stripe-mock, non c'è una vera carta da usare: l'app chiama simulate-payment
                'simulated' => $this->simulated(),
            ],
        ], 201);
    }

    /**
     * Storico degli ordini del cliente (US-C11), dal più recente, fino a 50. Non compaiono gli ordini mai pagati
     * e già chiusi (scaduti o con pagamento fallito): per il cliente non sono ordini. Quelli ancora in attesa sì,
     * con `reserved_until` per ricordargli fino a quando può pagare.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('customer_id', $request->user()->id)
            ->whereNotIn('status', [Order::EXPIRED, Order::PAYMENT_FAILED])
            ->with(['items.box', 'seller', 'reservations'])
            ->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'payment_simulated' => $this->simulated(),
            'data' => $orders->map(fn (Order $o) => OrderResource::make($o))->all(),
        ]);
    }

    /** Stato di un proprio ordine: l'app lo rilegge dopo il pagamento per sapere se è PAID. */
    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json(OrderResource::make($this->own($request, $order)) + ['payment_simulated' => $this->simulated()]);
    }

    /**
     * Solo sviluppo (stripe-mock non ha carte né webhook): esegue il pagamento di prova passando dallo stesso
     * codice del webhook vero. `outcome`: succeeded (default) o failed.
     */
    public function simulatePayment(Request $request, int $order, PaymentEventHandler $handler): JsonResponse
    {
        if (! $this->simulated()) {
            ApiError::throw('NOT_FOUND', 'Non trovato.', 404);
        }

        $outcome = $request->validate(['outcome' => ['nullable', 'in:succeeded,failed']])['outcome'] ?? 'succeeded';
        $model = $this->own($request, $order);

        if (! $model->stripe_payment_intent_id) {
            ApiError::throw('INVALID_STATE', 'Questo ordine non ha un pagamento da completare.', 409);
        }

        $handler->handle([
            'id' => 'evt_sim_'.Str::random(24),
            'type' => $outcome === 'failed' ? 'payment_intent.payment_failed' : 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => $model->stripe_payment_intent_id,
                'object' => 'payment_intent',
                'amount' => $model->total_cents,
                'metadata' => ['order_id' => (string) $model->id],
            ]],
        ]);

        return response()->json(OrderResource::make($model->fresh()));
    }

    private function simulated(): bool
    {
        return (bool) config('toogood.payments.simulated');
    }

    private function own(Request $request, int $id): Order
    {
        return Order::where('customer_id', $request->user()->id)->find($id)
            ?? ApiError::throw('NOT_FOUND', 'Ordine non trovato.', 404);
    }
}

<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\StockReservation;
use App\Services\Stripe\PaymentService;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cambi di stato di un ordine legati al pagamento e al rilascio dello stock.
 *
 * Ogni passaggio blocca la riga dell'ordine (`lockForUpdate`) e controlla lo stato dentro la transazione:
 * un webhook ripetuto, il comando di scadenza e un nuovo carrello dello stesso cliente possono arrivare
 * insieme senza che lo stock venga restituito due volte o che un ordine cambi stato due volte.
 */
class OrderLifecycle
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PaymentService $payments,
        private readonly OrderNotifier $notifier,
        private readonly PickupService $pickup,
    ) {}

    /**
     * Libera lo stock di un ordine ancora in attesa di pagamento e ne imposta il nuovo stato.
     * Va chiamato dentro una transazione. Restituisce se è stato liberato e l'eventuale PaymentIntent da annullare.
     *
     * @return array{released: bool, intent: ?string}
     */
    public function releaseLocked(int $orderId, string $newStatus): array
    {
        $order = Order::lockForUpdate()->find($orderId);
        if (! $order || $order->status !== Order::PENDING_PAYMENT) {
            return ['released' => false, 'intent' => null];
        }

        $reservations = StockReservation::where('order_id', $order->id)->whereNull('released_at')
            ->orderBy('box_offer_id')->lockForUpdate()->get();

        foreach ($reservations as $reservation) {
            DB::table('box_offers')->where('id', $reservation->box_offer_id)->increment('stock_available', $reservation->quantity);
            $reservation->forceFill(['released_at' => $this->clock->now()])->save();
        }

        $order->status = $newStatus;
        $order->save();

        return ['released' => true, 'intent' => $order->stripe_payment_intent_id];
    }

    /** Come `releaseLocked`, in una propria transazione; poi annulla il pagamento su Stripe (senza far fallire nulla se non risponde). */
    public function release(Order $order, string $newStatus): bool
    {
        // Ripetuta in caso di deadlock con un acquisto in corso sulle stesse box
        $result = DB::transaction(fn () => $this->releaseLocked($order->id, $newStatus), 3);

        if ($result['released'] && $result['intent']) {
            $this->cancelIntentQuietly($result['intent']);
        }

        return $result['released'];
    }

    public function cancelIntentQuietly(string $intentId): void
    {
        try {
            $this->payments->cancelIntent($intentId);
        } catch (Throwable $e) {
            // Lo stock è già tornato disponibile: un PaymentIntent rimasto aperto scade da solo su Stripe
            Log::warning("Annullamento del PaymentIntent {$intentId} non riuscito: {$e->getMessage()}");
        }
    }

    /**
     * Pagamento riuscito. Se l'ordine aspettava il pagamento diventa PAID; se era già scaduto o fallito
     * (pagamento tardivo) si rimborsa tutto e si avvisa il cliente. Ripetere lo stesso evento non fa nulla.
     *
     * @return 'paid'|'late_refund'|'ignored'|'unknown'
     */
    public function onPaymentSucceeded(string $intentId): string
    {
        [$order, $kind] = DB::transaction(function () use ($intentId) {
            $order = Order::where('stripe_payment_intent_id', $intentId)->lockForUpdate()->first();

            if (! $order) {
                return [null, 'unknown'];
            }
            // Anche se la prenotazione è scaduta da pochi secondi, finché il comando non l'ha liberata lo stock è
            // ancora nostro: il pagamento si onora.
            if ($order->status === Order::PENDING_PAYMENT) {
                $order->status = Order::PAID;
                $order->paid_at = $this->clock->now();
                $order->save();
                // QR e codice di ritiro: pronti già per l'email di conferma
                $this->pickup->issue($order);

                return [$order, 'paid'];
            }
            if (in_array($order->status, [Order::EXPIRED, Order::PAYMENT_FAILED], true)) {
                return [$order, 'late_refund'];
            }

            return [$order, 'ignored'];
        });

        if ($kind === 'paid') {
            $this->notifier->orderPaid($order);
        } elseif ($kind === 'late_refund') {
            $this->refundLatePayment($order);
        }

        return $kind;
    }

    /** Pagamento fallito: lo stock torna disponibile. */
    public function onPaymentFailed(string $intentId): bool
    {
        $order = Order::where('stripe_payment_intent_id', $intentId)->first();

        return $order ? $this->release($order, Order::PAYMENT_FAILED) : false;
    }

    private function refundLatePayment(Order $order): void
    {
        // Se Stripe non risponde l'eccezione risale al webhook, che risponde 500 e Stripe riprova più tardi
        $this->payments->refund($order->stripe_payment_intent_id);

        DB::transaction(function () use ($order) {
            $fresh = Order::lockForUpdate()->find($order->id);
            if (in_array($fresh->status, [Order::EXPIRED, Order::PAYMENT_FAILED], true)) {
                $fresh->status = Order::REFUNDED_FULL;
                $fresh->save();
            }
        });

        $this->notifier->latePaymentRefunded($order->fresh());
    }
}

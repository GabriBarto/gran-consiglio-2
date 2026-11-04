<?php

namespace App\Services\Orders;

use App\Mail\LatePaymentRefundMail;
use App\Mail\NewOrderMail;
use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email legate agli ordini. Partono dopo che lo stato è già salvato e un errore di invio non annulla nulla:
 * un pagamento riuscito non deve fallire perché il server di posta non risponde.
 */
class OrderNotifier
{
    /** Conferma al cliente (US-C08) e avviso di nuovo ordine al venditore (US-V12). */
    public function orderPaid(Order $order): void
    {
        $order->load(['items.box', 'seller.user', 'customer']);

        $this->send(fn () => Mail::to($order->customer->email)->send(new OrderConfirmationMail($order)), $order, 'conferma al cliente');
        $this->send(fn () => Mail::to($order->seller->user->email)->send(new NewOrderMail($order)), $order, 'avviso al venditore');
    }

    /** Il pagamento è arrivato dopo la scadenza della prenotazione: rimborsato per intero. */
    public function latePaymentRefunded(Order $order): void
    {
        $order->load(['seller', 'customer']);

        $this->send(fn () => Mail::to($order->customer->email)->send(new LatePaymentRefundMail($order)), $order, 'rimborso pagamento tardivo');
    }

    private function send(callable $send, Order $order, string $what): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::warning("Email non inviata per l'ordine {$order->number()} ({$what}): {$e->getMessage()}");
        }
    }
}

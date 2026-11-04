<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\StockReservation;
use App\Services\Orders\OrderLifecycle;
use App\Support\Clock;
use Illuminate\Console\Command;

/**
 * Libera le box tenute da parte per ordini che non sono stati pagati in tempo e annulla il loro PaymentIntent.
 * Pianificato ogni minuto (routes/console.php). L'ora è quella del Clock, quindi in sviluppo si può far
 * avanzare il tempo con `php artisan clock advance 15m` per vedere la scadenza.
 */
class ReleaseExpiredReservations extends Command
{
    protected $signature = 'reservations:release';

    protected $description = 'Libera lo stock degli ordini non pagati entro il tempo di prenotazione';

    public function handle(OrderLifecycle $lifecycle, Clock $clock): int
    {
        $orderIds = StockReservation::whereNull('released_at')->where('expires_at', '<=', $clock->now())->distinct()->pluck('order_id');

        $released = 0;
        foreach (Order::whereIn('id', $orderIds)->where('status', Order::PENDING_PAYMENT)->get() as $order) {
            // release() ricontrolla lo stato con la riga bloccata: se nel frattempo è stato pagato non fa nulla
            if ($lifecycle->release($order, Order::EXPIRED)) {
                $released++;
            }
        }

        $this->info("Ordini scaduti liberati: {$released}");

        return self::SUCCESS;
    }
}

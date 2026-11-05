<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A fine fascia di ritiro, gli ordini pagati e mai scansionati diventano NOT_PICKED_UP (RB-09: nessun rimborso).
 * Pianificato ogni minuto. L'ora è quella del Clock: in sviluppo si può far avanzare il tempo con
 * `php artisan clock advance 12h` per vedere l'effetto senza aspettare la sera.
 */
class CloseExpiredPickups extends Command
{
    protected $signature = 'orders:close-pickup';

    protected $description = 'Segna come non ritirati gli ordini pagati la cui fascia di ritiro è finita';

    public function handle(Clock $clock): int
    {
        // Solo dopo la fine: all'ultimo istante della fascia la scansione vale ancora, quindi l'ordine non si chiude
        $ids = Order::where('status', Order::PAID)->where('pickup_window_end_at', '<', $clock->now())->pluck('id');

        $closed = 0;
        foreach ($ids as $id) {
            $closed += DB::transaction(function () use ($id, $clock) {
                // Si ricontrolla con la riga bloccata: se il venditore ha scansionato un attimo prima, l'ordine non si tocca
                $order = Order::lockForUpdate()->find($id);
                if (! $order || $order->status !== Order::PAID || $order->pickup_window_end_at->gte($clock->now())) {
                    return 0;
                }

                $order->status = Order::NOT_PICKED_UP;
                $order->save();

                return 1;
            });
        }

        $this->info("Ordini non ritirati: {$closed}");

        return self::SUCCESS;
    }
}

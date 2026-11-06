<?php

namespace App\Services\Payouts;

use App\Models\Order;
use App\Models\Payout;
use App\Services\Boxes\BusinessConfig;
use App\Services\Stripe\TransferService;
use App\Support\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pagamento ritardato al venditore (RB-11): 24 ore dopo la fine della fascia di ritiro, sia per gli ordini
 * ritirati sia per quelli non ritirati. Importo per box = max(0, prezzo − tariffa): il guadagno può essere
 * negativo, ma il trasferimento no e al venditore non si addebita mai nulla (RB-05).
 */
class PayoutService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BusinessConfig $config,
        private readonly TransferService $transfers,
    ) {}

    /** Importo da trasferire per un ordine, in centesimi. */
    public static function amountFor(Order $order): int
    {
        $order->loadMissing('items');

        return (int) $order->items->sum(fn ($i) => $i->quantity * max(0, $i->unit_price_cents - $i->unit_fee_cents));
    }

    /** Programma il payout di un ordine pagato. Ripetibile: ne esiste uno solo per ordine. */
    public function schedule(Order $order): Payout
    {
        $existing = Payout::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        $payout = new Payout;
        $payout->order_id = $order->id;
        $payout->amount_cents = self::amountFor($order);
        $payout->scheduled_at = $order->pickup_window_end_at->copy()->addHours($this->config->int('payout_delay_hours'));
        $payout->status = Payout::SCHEDULED;

        try {
            $payout->save();
        } catch (UniqueConstraintViolationException) {
            // Un'altra richiesta l'ha creato un attimo prima: va bene quello
            return Payout::where('order_id', $order->id)->firstOrFail();
        }

        return $payout;
    }

    /** Programma i payout degli ordini pagati che ancora non ne hanno (ordini precedenti a questa funzione). */
    public function scheduleMissing(): int
    {
        $orders = Order::whereIn('status', [Order::PAID, Order::PICKED_UP, Order::NOT_PICKED_UP, Order::IN_COMPLAINT, Order::REFUNDED_PARTIAL, Order::COMPLAINT_REJECTED])
            ->whereDoesntHave('payout')->get();
        foreach ($orders as $order) {
            $this->schedule($order);
        }

        return $orders->count();
    }

    /**
     * Esegue i payout arrivati a scadenza.
     *
     * @return array{sent: int, skipped: int, held: int, failed: int}
     */
    public function runDue(): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'held' => 0, 'failed' => 0];

        $ids = Payout::where('status', Payout::SCHEDULED)->where('scheduled_at', '<=', $this->clock->now())->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $outcome = $this->process($id);
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return 'sent'|'skipped'|'held'|'failed' */
    private function process(int $payoutId): string
    {
        try {
            return DB::transaction(function () use ($payoutId) {
                // Con la riga bloccata e lo stato ricontrollato, due esecuzioni insieme non pagano due volte
                $payout = Payout::lockForUpdate()->find($payoutId);
                if (! $payout || $payout->status !== Payout::SCHEDULED || $payout->scheduled_at->gt($this->clock->now())) {
                    return 'skipped';
                }
                $order = Order::lockForUpdate()->with('seller')->findOrFail($payout->order_id);

                if ($order->status === Order::IN_COMPLAINT) {
                    $payout->status = Payout::HELD;
                    $payout->save();

                    return 'held';
                }
                // Rimborsato per intero, oppure guadagno nullo: niente da trasferire
                if ($order->status === Order::REFUNDED_FULL || $payout->amount_cents === 0) {
                    $payout->status = Payout::SKIPPED;
                    $payout->save();

                    return 'skipped';
                }
                if (! $order->seller->stripe_account_id) {
                    throw new \RuntimeException('Il venditore non ha un conto di pagamento collegato.');
                }

                $payout->stripe_transfer_id = $this->transfers->send($payout, $order->seller->stripe_account_id);
                $payout->status = Payout::SENT;
                $payout->sent_at = $this->clock->now();
                $payout->save();

                return 'sent';
            });
        } catch (Throwable $e) {
            // Resta SCHEDULED: ci riprova la prossima esecuzione. Gli altri payout non si fermano.
            Log::warning("Payout {$payoutId} non eseguito: {$e->getMessage()}");

            return 'failed';
        }
    }
}

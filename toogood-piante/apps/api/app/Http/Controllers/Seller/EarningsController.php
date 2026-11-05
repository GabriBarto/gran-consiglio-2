<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payout;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Incassi del venditore (US-V16): ordini, tariffe trattenute, importi trasferiti, in attesa e sospesi.
 * Il guadagno di un ordine (prezzo − tariffa) può essere negativo e si mostra com'è; il trasferimento
 * invece non scende mai sotto 0 e al venditore non si addebita nulla (RB-05).
 */
class EarningsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $seller = $request->user()->seller
            ?? ApiError::throw('PROFILE_REQUIRED', 'Compila prima i dati dell\'attività.', 409);

        // Un ordine rimborsato per intero non è un incasso: non compare né nei totali né nell'elenco
        $orders = Order::where('seller_id', $seller->id)->where('status', '!=', Order::REFUNDED_FULL)
            ->whereHas('payout')->with(['payout', 'items'])->orderByDesc('pickup_window_end_at')->orderByDesc('id')->get();

        $rows = $orders->map(function (Order $order) {
            $gross = (int) $order->items->sum(fn ($i) => $i->quantity * $i->unit_price_cents);
            $fee = (int) $order->items->sum(fn ($i) => $i->quantity * $i->unit_fee_cents);
            $payout = $order->payout;

            // Con un reclamo aperto l'importo è "sospeso" anche se il payout non è ancora stato toccato (US-V16 CA2)
            $state = $order->status === 'IN_COMPLAINT' && $payout->status === Payout::SCHEDULED ? Payout::HELD : $payout->status;

            return [
                'order_id' => $order->id,
                'number' => $order->number(),
                'order_date' => $order->order_date->toDateString(),
                'order_status' => $order->status,
                'gross_cents' => $gross,
                'fee_cents' => $fee,
                'earning_cents' => $gross - $fee,
                'payout_cents' => $payout->amount_cents,
                'payout_status' => $state,
                'scheduled_at' => $payout->scheduled_at->toIso8601String(),
                'sent_at' => $payout->sent_at?->toIso8601String(),
            ];
        });

        $sumBy = fn (string $status) => (int) $rows->where('payout_status', $status)->sum('payout_cents');

        return response()->json([
            'summary' => [
                'orders' => $rows->count(),
                'gross_cents' => (int) $rows->sum('gross_cents'),
                'fees_cents' => (int) $rows->sum('fee_cents'),
                // Può essere negativo: il sistema lo mostra, ma non lo addebita
                'earning_cents' => (int) $rows->sum('earning_cents'),
                'transferred_cents' => $sumBy(Payout::SENT),
                'pending_cents' => $sumBy(Payout::SCHEDULED),
                'held_cents' => $sumBy(Payout::HELD),
            ],
            'data' => $rows->take(100)->values()->all(),
        ]);
    }
}

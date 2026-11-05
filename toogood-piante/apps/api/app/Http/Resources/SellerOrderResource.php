<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Come il venditore vede un ordine del suo negozio (US-V14). Del cliente vede solo il nome utente:
 * email e altri dati personali non servono per consegnare una box e non escono.
 */
final class SellerOrderResource
{
    /** @return array<string, mixed> */
    public static function make(Order $order): array
    {
        $order->loadMissing(['items.box', 'customer']);

        $items = $order->items->map(function ($item) {
            $type = DB::table('box_types')->find($item->box->box_type_id);

            return [
                'quantity' => $item->quantity,
                'description' => $item->box->description ?: $type->label,
            ];
        })->all();

        return [
            'id' => $order->id,
            'number' => $order->number(),
            'status' => $order->status,
            'customer' => ['username' => $order->customer->username],
            'items' => $items,
            'units' => array_sum(array_column($items, 'quantity')),
            'total_cents' => $order->total_cents,
            'pickup_window_end_at' => $order->pickup_window_end_at->toIso8601String(),
            'picked_up_at' => $order->picked_up_at?->toIso8601String(),
        ];
    }
}

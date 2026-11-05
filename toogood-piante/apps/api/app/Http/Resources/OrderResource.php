<?php

namespace App\Http\Resources;

use App\Models\Order;

/** Come il cliente vede il proprio ordine. La tariffa di servizio è un dato del venditore e non compare. */
final class OrderResource
{
    /** @return array<string, mixed> */
    public static function make(Order $order): array
    {
        $order->loadMissing(['items.box', 'seller', 'reservations']);

        $items = $order->items->map(function ($item) {
            $box = $item->box;
            $type = \DB::table('box_types')->find($box->box_type_id);
            $category = \DB::table('categories')->find($box->category_id);

            return [
                'box_id' => $item->box_offer_id,
                'box_type' => $type->label,
                'category' => $category->label,
                'description' => $box->description,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'line_total_cents' => $item->unit_price_cents * $item->quantity,
                'min_saving_cents' => max(0, $box->list_value_cents - $item->unit_price_cents) * $item->quantity,
            ];
        })->all();

        $seller = $order->seller;
        // Finché non è pagato, fino a quando le box restano tenute da parte
        $reservedUntil = $order->status === Order::PENDING_PAYMENT
            ? $order->reservations->whereNull('released_at')->min('expires_at')
            : null;

        return [
            'id' => $order->id,
            'number' => $order->number(),
            'status' => $order->status,
            'order_date' => $order->order_date->toDateString(),
            'total_cents' => $order->total_cents,
            'min_saving_cents' => array_sum(array_column($items, 'min_saving_cents')),
            'items' => $items,
            'shop' => [
                'id' => $seller->id,
                'business_name' => $seller->business_name,
                'address' => $seller->address,
                'city' => $seller->city,
                'pickup_from' => substr((string) $seller->pickup_from, 0, 5),
                'pickup_to' => substr((string) $seller->pickup_to, 0, 5),
            ],
            'pickup_window_end_at' => $order->pickup_window_end_at->toIso8601String(),
            'reserved_until' => $reservedUntil?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'picked_up_at' => $order->picked_up_at?->toIso8601String(),
        ];
    }
}

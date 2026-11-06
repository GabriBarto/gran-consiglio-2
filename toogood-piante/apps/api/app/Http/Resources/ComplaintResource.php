<?php

namespace App\Http\Resources;

use App\Models\Complaint;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/** Un reclamo visto dal cliente, dal venditore e dall'admin. Ognuno vede solo quello che gli serve. */
final class ComplaintResource
{
    private const LABELS = [
        Complaint::CLOSED_OR_NOT_DELIVERED => 'Negozio chiuso o box non consegnata',
        Complaint::VALUE_BELOW_DECLARED => 'Valore evidentemente inferiore al minimo dichiarato',
        Complaint::PLANTS_DAMAGED => 'Piante morte o danneggiate',
        Complaint::NOT_AS_DECLARED => 'Caratteristiche dichiarate non rispettate',
    ];

    public static function reasonLabel(string $reason): string
    {
        return self::LABELS[$reason] ?? $reason;
    }

    /** @return array<string, mixed> */
    private static function base(Complaint $c): array
    {
        return [
            'id' => $c->id,
            'status' => $c->status,
            'reason' => $c->reason,
            'reason_label' => self::reasonLabel($c->reason),
            'description' => $c->description,
            'decision_note' => $c->decision_note,
            'refund_cents' => $c->refund_cents,
            'created_at' => $c->created_at?->toIso8601String(),
            'decided_at' => $c->decided_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function forCustomer(Complaint $c): array
    {
        return self::base($c);
    }

    /** Per il venditore: l'ordine e il nome utente del cliente, mai email o altri dati. */
    public static function forSeller(Complaint $c): array
    {
        $c->loadMissing(['order', 'customer']);

        return self::base($c) + [
            'order' => ['id' => $c->order->id, 'number' => $c->order->number(), 'total_cents' => $c->order->total_cents],
            'customer' => ['username' => $c->customer->username],
        ];
    }

    /** Per l'admin: tutto quello che serve a decidere, compreso lo stato del pagamento al venditore. */
    public static function forAdmin(Complaint $c): array
    {
        $c->loadMissing(['order.items.box', 'order.seller', 'order.payout', 'order.refunds', 'customer']);
        $order = $c->order;

        $items = $order->items->map(function ($item) {
            $type = DB::table('box_types')->find($item->box->box_type_id);

            return [
                'quantity' => $item->quantity,
                'description' => $item->box->description ?: $type->label,
                'box_type' => $type->label,
                'unit_price_cents' => $item->unit_price_cents,
                'list_value_cents' => $item->box->list_value_cents,
            ];
        })->all();

        $payout = $order->payout;

        return self::base($c) + [
            'order' => [
                'id' => $order->id,
                'number' => $order->number(),
                'status' => $order->status,
                'order_date' => $order->order_date->toDateString(),
                'total_cents' => $order->total_cents,
                'paid_at' => $order->paid_at?->toIso8601String(),
                'picked_up_at' => $order->picked_up_at?->toIso8601String(),
                'pickup_window_end_at' => $order->pickup_window_end_at->toIso8601String(),
                'items' => $items,
            ],
            'shop' => ['id' => $order->seller->id, 'business_name' => $order->seller->business_name, 'city' => $order->seller->city],
            'customer' => ['username' => $c->customer->username, 'email' => $c->customer->email],
            'payout' => $payout ? [
                'status' => $payout->status,
                'amount_cents' => $payout->amount_cents,
                'already_sent' => $payout->status === Payout::SENT,
            ] : null,
            'refunded_cents' => (int) $order->refunds->sum('amount_cents'),
        ];
    }
}

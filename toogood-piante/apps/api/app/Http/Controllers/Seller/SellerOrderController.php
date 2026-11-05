<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerOrderResource;
use App\Models\Order;
use App\Support\ApiError;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gli ordini del negozio nel giorno scelto, con lo stato del ritiro (US-V14). */
class SellerOrderController extends Controller
{
    /** Stati che al venditore interessano: da ritirare, ritirato, non ritirato e quelli già decisi. Gli ordini mai pagati non compaiono. */
    private const VISIBLE = [
        Order::PAID, Order::PICKED_UP, Order::NOT_PICKED_UP,
        'IN_COMPLAINT', Order::REFUNDED_FULL, 'REFUNDED_PARTIAL', 'COMPLAINT_REJECTED',
    ];

    public function index(Request $request, Clock $clock): JsonResponse
    {
        $seller = $request->user()->seller
            ?? ApiError::throw('PROFILE_REQUIRED', 'Compila prima i dati dell\'attività.', 409);

        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $clock->todayLocal()->toDateString();

        $orders = Order::where('seller_id', $seller->id)->whereDate('order_date', $date)
            ->whereIn('status', self::VISIBLE)->with(['items.box', 'customer'])->orderBy('id')->get();

        return response()->json([
            'date' => $date,
            'summary' => [
                'to_pick_up' => $orders->where('status', Order::PAID)->count(),
                'units_to_pick_up' => (int) $orders->where('status', Order::PAID)->sum(fn (Order $o) => $o->items->sum('quantity')),
                'picked_up' => $orders->where('status', Order::PICKED_UP)->count(),
                'not_picked_up' => $orders->where('status', Order::NOT_PICKED_UP)->count(),
            ],
            'data' => $orders->map(fn (Order $o) => SellerOrderResource::make($o))->all(),
        ]);
    }
}

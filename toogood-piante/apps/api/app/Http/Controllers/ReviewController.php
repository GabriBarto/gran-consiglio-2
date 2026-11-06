<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Models\Review;
use App\Support\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recensione del cliente dopo il ritiro (US-C12): una per ordine, voto da 1 a 5 e commento facoltativo. */
class ReviewController extends Controller
{
    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $model = Order::where('customer_id', $request->user()->id)->find($order)
            ?? ApiError::throw('NOT_FOUND', 'Ordine non trovato.', 404);

        // Solo chi ha davvero ritirato (RB: la recensione si abilita con il ritiro)
        if (! $model->picked_up_at) {
            ApiError::throw('REVIEW_NOT_ALLOWED', 'Puoi lasciare una recensione solo dopo aver ritirato la box.', 409);
        }

        $review = new Review;
        $review->order_id = $model->id;
        $review->customer_id = $model->customer_id;
        $review->seller_id = $model->seller_id;
        $review->rating = $data['rating'];
        $review->comment = isset($data['comment']) && trim($data['comment']) !== '' ? trim($data['comment']) : null;
        $review->status = Review::VISIBLE;

        try {
            // order_id è unico: due richieste insieme non creano due recensioni
            $review->save();
        } catch (UniqueConstraintViolationException) {
            ApiError::throw('ALREADY_REVIEWED', 'Hai già recensito questo ordine.', 409);
        }

        return response()->json(ReviewResource::make($review), 201);
    }
}

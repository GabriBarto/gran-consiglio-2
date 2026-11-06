<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Models\Review;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Recensioni (US-V17) e reclami (US-V18) ricevuti dal negozio. Del cliente compare solo il nome utente. */
class FeedbackController extends Controller
{
    public function reviews(Request $request): JsonResponse
    {
        $seller = $request->user()->seller
            ?? ApiError::throw('PROFILE_REQUIRED', 'Compila prima i dati dell\'attività.', 409);

        $visible = Review::where('seller_id', $seller->id)->where('status', Review::VISIBLE);
        $summary = (clone $visible)->selectRaw('avg(rating) as average, count(*) as total')->first();

        $rows = $visible->with(['customer', 'order'])->orderByDesc('id')->limit(50)->get()->map(fn (Review $r) => [
            'id' => $r->id,
            'rating' => (int) $r->rating,
            'comment' => $r->comment,
            'created_at' => $r->created_at->toIso8601String(),
            'order_number' => $r->order->number(),
            'customer' => ['username' => $r->customer->username],
        ])->all();

        return response()->json([
            'summary' => [
                'average' => $summary->total ? round((float) $summary->average, 1) : null,
                'count' => (int) $summary->total,
            ],
            'data' => $rows,
        ]);
    }

    public function complaints(Request $request): JsonResponse
    {
        $seller = $request->user()->seller
            ?? ApiError::throw('PROFILE_REQUIRED', 'Compila prima i dati dell\'attività.', 409);

        $complaints = Complaint::whereHas('order', fn ($q) => $q->where('seller_id', $seller->id))
            ->with(['order', 'customer'])->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'open' => $complaints->where('status', Complaint::OPEN)->count(),
            'data' => $complaints->map(fn (Complaint $c) => ComplaintResource::forSeller($c))->all(),
        ]);
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Review;

/** Una recensione. Del cliente compare solo il nome utente, come nella scheda del negozio. */
final class ReviewResource
{
    /** @return array<string, mixed> */
    public static function make(Review $review): array
    {
        return [
            'id' => $review->id,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }
}

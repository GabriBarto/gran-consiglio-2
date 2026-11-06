<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recensione di un ordine ritirato (US-C12): una per ordine, voto da 1 a 5 e commento facoltativo. */
class Review extends Model
{
    public const VISIBLE = 'VISIBLE';

    public const REMOVED = 'REMOVED';

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}

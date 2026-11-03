<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerDocument extends Model
{
    protected $guarded = [];

    // La chiave dell'oggetto nello storage non esce mai dall'API
    protected $hidden = ['storage_key'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}

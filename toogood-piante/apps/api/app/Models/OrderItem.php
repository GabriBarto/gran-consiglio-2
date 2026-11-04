<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una riga d'ordine: prezzo e tariffa sono copiati dalla box al momento dell'acquisto (istantanea). */
class OrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(BoxOffer::class, 'box_offer_id')->withTrashed();
    }
}

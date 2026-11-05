<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Il "biglietto" di ritiro di un ordine pagato: monouso, valido solo nella fascia di ritiro del giorno. */
class PickupToken extends Model
{
    // Gli imposta solo il servizio del ritiro
    protected $guarded = ['*'];

    protected $hidden = ['jti'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}

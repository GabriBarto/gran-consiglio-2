<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Ordine di un cliente presso un solo negozio. Importi in centesimi. */
class Order extends Model
{
    public const PENDING_PAYMENT = 'PENDING_PAYMENT';

    public const PAID = 'PAID';

    public const PICKED_UP = 'PICKED_UP';

    public const NOT_PICKED_UP = 'NOT_PICKED_UP';

    public const EXPIRED = 'EXPIRED';

    public const PAYMENT_FAILED = 'PAYMENT_FAILED';

    public const REFUNDED_FULL = 'REFUNDED_FULL';

    // Lo stato e gli importi li imposta solo il servizio dell'ordine, mai la richiesta del client
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'no_refund_accepted_at' => 'datetime',
            'pickup_window_end_at' => 'datetime',
            'paid_at' => 'datetime',
            'picked_up_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function pickupToken(): HasOne
    {
        return $this->hasOne(PickupToken::class);
    }

    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** Numero leggibile dell'ordine, es. TG-000042. */
    public function number(): string
    {
        return 'TG-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}

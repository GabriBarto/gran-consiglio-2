<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reclamo di un cliente su un ordine (US-C14), deciso dall'admin (US-S05). Importi in centesimi. */
class Complaint extends Model
{
    // I quattro casi ammessi: a, b, c, d
    public const CLOSED_OR_NOT_DELIVERED = 'CLOSED_OR_NOT_DELIVERED';

    public const VALUE_BELOW_DECLARED = 'VALUE_BELOW_DECLARED';

    public const PLANTS_DAMAGED = 'PLANTS_DAMAGED';

    public const NOT_AS_DECLARED = 'NOT_AS_DECLARED';

    public const REASONS = [
        self::CLOSED_OR_NOT_DELIVERED, self::VALUE_BELOW_DECLARED, self::PLANTS_DAMAGED, self::NOT_AS_DECLARED,
    ];

    public const OPEN = 'OPEN';

    public const ACCEPTED_FULL = 'ACCEPTED_FULL';

    public const ACCEPTED_PARTIAL = 'ACCEPTED_PARTIAL';

    public const REJECTED = 'REJECTED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

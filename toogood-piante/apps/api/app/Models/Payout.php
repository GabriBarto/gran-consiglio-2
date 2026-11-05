<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trasferimento del guadagno al venditore per un ordine (RB-11). Importo in centesimi, mai negativo. */
class Payout extends Model
{
    public const SCHEDULED = 'SCHEDULED';

    public const HELD = 'HELD';

    public const SENT = 'SENT';

    public const SKIPPED = 'SKIPPED';

    public const REVERSED = 'REVERSED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

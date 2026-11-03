<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seller extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const VAT_VALID = 'VALID';

    public const VAT_UNAVAILABLE = 'UNAVAILABLE';

    // verification_status, stripe_* e vat_check_status non sono assegnabili in massa:
    // li impostano solo il servizio di onboarding e l'admin.
    protected $fillable = [
        'business_name', 'vat_number', 'phone', 'address', 'city', 'latitude', 'longitude',
        'opens_at', 'closes_at', 'pickup_from', 'pickup_to',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'verified_at' => 'datetime',
            'stripe_onboarded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SellerDocument::class);
    }

    /** Dati dell'attività modificabili solo finché non sono stati approvati (o dopo un rifiuto). */
    public function isEditable(): bool
    {
        return in_array($this->verification_status, [self::STATUS_PENDING, self::STATUS_REJECTED], true);
    }

    public function stripeOnboarded(): bool
    {
        return $this->stripe_onboarded_at !== null;
    }

    /** Può pubblicare box: approvato dall'admin e con il conto Stripe collegato. */
    public function canPublish(): bool
    {
        return $this->verification_status === self::STATUS_APPROVED && $this->stripeOnboarded();
    }
}

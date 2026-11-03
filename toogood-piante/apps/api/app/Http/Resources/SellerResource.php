<?php

namespace App\Http\Resources;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Seller */
class SellerResource extends JsonResource
{
    public static $wrap = null;

    /** Quello che vede il venditore: stato, dati, documenti e collegamento Stripe. */
    public static function forOwner(User $user): array
    {
        $seller = $user->seller()->with('documents')->first();

        if (! $seller) {
            return ['status' => 'INCOMPLETE', 'seller' => null, 'documents' => [], 'stripe_connected' => false, 'can_publish' => false];
        }

        return [
            'status' => $seller->verification_status,
            'verification_note' => $seller->verification_note,
            'editable' => $seller->isEditable(),
            'seller' => self::details($seller),
            'documents' => self::documents($seller),
            'stripe_connected' => $seller->stripeOnboarded(),
            'can_publish' => $seller->canPublish(),
        ];
    }

    /** Quello che vede l'admin nella coda delle verifiche: in più l'esito dei controlli automatici. */
    public static function forAdmin(Seller $seller): array
    {
        return [
            'id' => $seller->id,
            'status' => $seller->verification_status,
            'verification_note' => $seller->verification_note,
            'verified_at' => $seller->verified_at?->toIso8601String(),
            'submitted_at' => $seller->updated_at?->toIso8601String(),
            'email' => $seller->user->email,
            'username' => $seller->user->username,
            'vat_check_status' => $seller->vat_check_status,
            'stripe_connected' => $seller->stripeOnboarded(),
            'seller' => self::details($seller),
            'documents' => self::documents($seller),
        ];
    }

    /** @return array<string, mixed> */
    private static function details(Seller $seller): array
    {
        return [
            'business_name' => $seller->business_name,
            'vat_number' => $seller->vat_number,
            'phone' => $seller->phone,
            'address' => $seller->address,
            'city' => $seller->city,
            'latitude' => $seller->latitude,
            'longitude' => $seller->longitude,
            'opens_at' => substr((string) $seller->opens_at, 0, 5),
            'closes_at' => substr((string) $seller->closes_at, 0, 5),
            'pickup_from' => substr((string) $seller->pickup_from, 0, 5),
            'pickup_to' => substr((string) $seller->pickup_to, 0, 5),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function documents(Seller $seller): array
    {
        return $seller->documents->map(fn ($d) => [
            'id' => $d->id,
            'name' => $d->original_name,
            'mime_type' => $d->mime_type,
            'size_bytes' => $d->size_bytes,
            'uploaded_at' => $d->created_at->toIso8601String(),
        ])->all();
    }

    public function toArray(Request $request): array
    {
        return self::forAdmin($this->resource);
    }
}

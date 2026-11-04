<?php

namespace App\Http\Resources;

use App\Models\BoxOffer;
use App\Models\Seller;
use App\Services\Boxes\OrderWindow;
use Illuminate\Support\Facades\DB;

/**
 * Cosa vede chiunque (anche senza account) di un negozio: dati pubblici dell'attività e box di oggi.
 * Partita IVA, telefono, documenti e stato della verifica non compaiono mai.
 */
final class ShopResource
{
    /** @return array<string, mixed> una riga della ricerca */
    public static function summary(Seller $seller, OrderWindow $window): array
    {
        return [
            'id' => $seller->id,
            'business_name' => $seller->business_name,
            'address' => $seller->address,
            'city' => $seller->city,
            'latitude' => (float) $seller->latitude,
            'longitude' => (float) $seller->longitude,
            'distance_m' => $seller->distance_m !== null ? (int) round($seller->distance_m) : null,
            'pickup_from' => substr((string) $seller->pickup_from, 0, 5),
            'pickup_to' => substr((string) $seller->pickup_to, 0, 5),
            'available_offers' => (int) ($seller->available_offers ?? 0),
            'available_units' => (int) ($seller->available_units ?? 0),
            'min_price_cents' => $seller->min_price_cents !== null ? (int) $seller->min_price_cents : null,
            'rating' => self::rating($seller->rating_average, $seller->rating_count),
            'orders_open' => $window->isOpen($seller),
        ];
    }

    /**
     * @param  list<BoxOffer>  $boxes
     * @return array<string, mixed> la scheda di un negozio
     */
    public static function detail(Seller $seller, array $boxes, ?float $distanceM, OrderWindow $window): array
    {
        $open = $window->isOpen($seller);
        $labels = self::attributeLabels($boxes);

        return [
            'shop' => [
                'id' => $seller->id,
                'business_name' => $seller->business_name,
                'address' => $seller->address,
                'city' => $seller->city,
                'latitude' => (float) $seller->latitude,
                'longitude' => (float) $seller->longitude,
                'distance_m' => $distanceM !== null ? (int) round($distanceM) : null,
                'opens_at' => substr((string) $seller->opens_at, 0, 5),
                'closes_at' => substr((string) $seller->closes_at, 0, 5),
                'pickup_from' => substr((string) $seller->pickup_from, 0, 5),
                'pickup_to' => substr((string) $seller->pickup_to, 0, 5),
                'order_deadline' => $window->deadline($seller)->toIso8601String(),
                'orders_open' => $open,
            ],
            'rating' => self::reviews($seller),
            'boxes' => array_map(fn (BoxOffer $b) => self::box($b, $open, $labels), $boxes),
        ];
    }

    /**
     * @param  array<string, array{code: string, label: string, group: string}>  $labels
     * @return array<string, mixed>
     */
    private static function box(BoxOffer $box, bool $ordersOpen, array $labels): array
    {
        $type = DB::table('box_types')->find($box->box_type_id);
        $category = DB::table('categories')->find($box->category_id);
        $soldOut = $box->stock_available <= 0;

        return [
            'id' => $box->id,
            'box_type' => ['code' => $type->code, 'label' => $type->label],
            'category' => ['code' => $category->code, 'label' => $category->label],
            'attributes' => array_values(array_filter(array_map(fn ($code) => $labels[$code] ?? null, $box->plantAttributes()))),
            'description' => $box->description,
            'price_cents' => $box->price_cents,
            'list_value_cents' => $box->list_value_cents,
            // "Risparmi almeno X €": il valore di listino è un minimo dichiarato dal venditore
            'min_saving_cents' => max(0, $box->list_value_cents - $box->price_cents),
            'stock_available' => $box->stock_available,
            'sold_out' => $soldOut,
            'orderable' => ! $soldOut && $ordersOpen,
            'unavailable_reason' => $soldOut ? 'SOLD_OUT' : ($ordersOpen ? null : 'ORDER_DEADLINE_PASSED'),
        ];
    }

    /** @return array{average: float|null, count: int} */
    private static function rating(mixed $average, mixed $count): array
    {
        return ['average' => $average !== null ? round((float) $average, 1) : null, 'count' => (int) $count];
    }

    /** @return array<string, mixed> media e ultime recensioni visibili */
    private static function reviews(Seller $seller): array
    {
        $visible = DB::table('reviews')->where('reviews.seller_id', $seller->id)->where('reviews.status', 'VISIBLE');

        $summary = (clone $visible)->selectRaw('avg(reviews.rating) as average, count(*) as total')->first();

        $latest = (clone $visible)
            ->join('users', 'users.id', '=', 'reviews.customer_id')
            ->orderByDesc('reviews.id')->limit(20)
            ->get(['reviews.rating', 'reviews.comment', 'reviews.created_at', 'users.username'])
            ->map(fn ($r) => [
                'rating' => (int) $r->rating,
                'comment' => $r->comment,
                'author' => $r->username,
                'created_at' => \Carbon\Carbon::parse($r->created_at)->toIso8601String(),
            ])->all();

        return self::rating($summary->average, $summary->total) + ['reviews' => $latest];
    }

    /**
     * @param  list<BoxOffer>  $boxes
     * @return array<string, array{code: string, label: string, group: string}>
     */
    private static function attributeLabels(array $boxes): array
    {
        $codes = array_values(array_unique(array_merge(...array_map(fn (BoxOffer $b) => $b->plantAttributes(), $boxes) ?: [[]])));

        return DB::table('plant_attributes')->whereIn('code', $codes)->orderBy('sort_order')->get(['code', 'label', 'group'])
            ->mapWithKeys(fn ($a) => [$a->code => ['code' => $a->code, 'label' => $a->label, 'group' => $a->group]])->all();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\ShopResource;
use App\Models\BoxOffer;
use App\Models\Seller;
use App\Services\Boxes\OrderWindow;
use App\Support\ApiError;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ricerca e scheda dei negozi. Pubbliche: ci arriva anche chi non ha ancora un account (US-N01, N02).
 * Compaiono solo negozi approvati e con il conto di pagamento collegato, cioè quelli che possono vendere.
 */
class ShopController extends Controller
{
    private const DEFAULT_LIMIT = 30;

    public function __construct(private readonly Clock $clock, private readonly OrderWindow $window) {}

    /**
     * Cerca per distanza (lat/lng), città e nome. Di default solo negozi con almeno una box disponibile oggi;
     * con `include_empty=1` anche gli altri. Con le coordinate la lista è ordinata per distanza, altrimenti per nome.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'include_empty' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $hasPosition = isset($data['lat'], $data['lng']);
        $lat = $hasPosition ? (float) $data['lat'] : null;
        $lng = $hasPosition ? (float) $data['lng'] : null;

        $query = $this->publishableShops()
            ->select('sellers.*')
            ->selectRaw('b.offers as available_offers, b.units as available_units, b.min_price as min_price_cents')
            ->selectRaw('r.average as rating_average, coalesce(r.total, 0) as rating_count')
            ->leftJoinSub($this->todayBoxes(), 'b', 'b.seller_id', '=', 'sellers.id')
            ->leftJoinSub($this->ratings(), 'r', 'r.seller_id', '=', 'sellers.id');

        if (! $request->boolean('include_empty')) {
            $query->whereNotNull('b.seller_id');
        }

        $this->applyTextFilters($query, $data['city'] ?? null, $data['q'] ?? null);

        if ($hasPosition) {
            // ST_Distance_Sphere vuole POINT(longitudine, latitudine); restituisce metri
            $query->selectRaw('ST_Distance_Sphere(POINT(sellers.longitude, sellers.latitude), POINT(?, ?)) as distance_m', [$lng, $lat]);

            if (isset($data['radius_km'])) {
                $this->applyRadius($query, $lat, $lng, (float) $data['radius_km']);
            }
            $query->orderBy('distance_m');
        }
        $query->orderBy('sellers.business_name')->orderBy('sellers.id');

        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);
        $shops = $query->limit($limit)->get();

        return response()->json([
            'date' => $this->clock->todayLocal()->toDateString(),
            'sorted_by' => $hasPosition ? 'distance' : 'name',
            'data' => $shops->map(fn (Seller $s) => ShopResource::summary($s, $this->window))->all(),
        ]);
    }

    /** La scheda di un negozio con le box di oggi (anche quelle esaurite, segnate come tali). */
    public function show(Request $request, int $seller): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        $shop = $this->publishableShops()->find($seller)
            ?? ApiError::throw('NOT_FOUND', 'Negozio non trovato.', 404);

        $distance = isset($data['lat'], $data['lng'])
            ? (float) DB::selectOne(
                'select ST_Distance_Sphere(POINT(?, ?), POINT(?, ?)) as d',
                [$shop->longitude, $shop->latitude, $data['lng'], $data['lat']],
            )->d
            : null;

        $boxes = BoxOffer::where('seller_id', $shop->id)
            ->whereDate('offer_date', $this->clock->todayLocal()->toDateString())
            ->where('status', BoxOffer::STATUS_ACTIVE)
            // Prima quelle ordinabili, poi le esaurite; a parità, la più economica
            ->orderByRaw('stock_available > 0 desc')->orderBy('price_cents')->orderBy('id')
            ->get()->all();

        return response()->json(ShopResource::detail($shop, $boxes, $distance, $this->window));
    }

    /** Negozi che possono vendere: approvati e con il conto Stripe collegato. */
    private function publishableShops(): Builder
    {
        return Seller::query()
            ->where('sellers.verification_status', Seller::STATUS_APPROVED)
            ->whereNotNull('sellers.stripe_onboarded_at');
    }

    /** Per ogni negozio: quante box (e unità) disponibili oggi e il prezzo più basso. */
    private function todayBoxes(): \Illuminate\Database\Query\Builder
    {
        return DB::table('box_offers')
            ->selectRaw('seller_id, count(*) as offers, sum(stock_available) as units, min(price_cents) as min_price')
            ->whereDate('offer_date', $this->clock->todayLocal()->toDateString())
            ->where('status', BoxOffer::STATUS_ACTIVE)
            ->where('stock_available', '>', 0)
            ->whereNull('deleted_at')
            ->groupBy('seller_id');
    }

    private function ratings(): \Illuminate\Database\Query\Builder
    {
        return DB::table('reviews')
            ->selectRaw('seller_id, avg(rating) as average, count(*) as total')
            ->where('status', 'VISIBLE')
            ->groupBy('seller_id');
    }

    /** Città e nome: contiene, senza distinguere maiuscole né accenti (collation del database). */
    private function applyTextFilters(Builder $query, ?string $city, ?string $name): void
    {
        foreach ([['sellers.city', $city], ['sellers.business_name', $name]] as [$column, $text]) {
            $text = trim((string) $text);
            if ($text !== '') {
                $query->where($column, 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text).'%');
            }
        }
    }

    /** Prefiltro per riquadro (usa l'indice su latitudine/longitudine), poi il raggio esatto. */
    private function applyRadius(Builder $query, float $lat, float $lng, float $km): void
    {
        $dLat = $km / 111.0;
        $dLng = $km / (111.0 * max(0.01, cos(deg2rad($lat))));

        $query->whereBetween('sellers.latitude', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('sellers.longitude', [$lng - $dLng, $lng + $dLng])
            ->havingRaw('distance_m <= ?', [$km * 1000]);
    }
}

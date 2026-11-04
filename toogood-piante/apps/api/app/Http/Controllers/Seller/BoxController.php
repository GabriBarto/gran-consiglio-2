<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\BoxResource;
use App\Models\BoxOffer;
use App\Models\Seller;
use App\Services\Boxes\BusinessConfig;
use App\Services\Boxes\PriceCalculator;
use App\Support\ApiError;
use App\Support\Clock;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Box del venditore: si creano per oggi, con prezzo calcolato dal server (US-V06, V08–V11). */
class BoxController extends Controller
{
    private const MAX_QUANTITY = 999;

    public function __construct(private readonly BusinessConfig $config, private readonly Clock $clock) {}

    /** Valori delle liste e parametri per il modulo di creazione. */
    public function options(): JsonResponse
    {
        return response()->json([
            'box_types' => DB::table('box_types')->orderBy('sort_order')->get(['id', 'code', 'label']),
            'categories' => DB::table('categories')->orderBy('sort_order')->get(['id', 'code', 'label']),
            'attributes' => DB::table('plant_attributes')->orderBy('sort_order')->get(['code', 'label', 'group']),
            'fee_cents' => $this->config->feeCents(),
            'discount_min_pct' => $this->config->discountMin(),
            'discount_max_pct' => $this->config->discountMax(),
            'max_quantity' => self::MAX_QUANTITY,
            'today' => $this->today(),
        ]);
    }

    /** Le box del giorno indicato (oggi se non specificato), dalla più recente. */
    public function index(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->today();

        $boxes = BoxOffer::where('seller_id', $seller->id)->whereDate('offer_date', $date)->orderByDesc('id')->get();

        return response()->json([
            'date' => $date,
            'can_publish' => $seller->canPublish(),
            'fee_cents' => $this->config->feeCents(),
            'data' => $boxes->map(fn (BoxOffer $b) => BoxResource::make($b))->all(),
        ]);
    }

    public function show(Request $request, int $box): JsonResponse
    {
        return response()->json(BoxResource::make($this->ownBox($request, $box)));
    }

    /** Crea una box per oggi. Con guadagno nullo o negativo serve la conferma esplicita (CA4). */
    public function store(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $this->assertCanPublish($seller);

        $data = $this->validated($request, creating: true);
        $quote = PriceCalculator::quote($data['list_value_cents'], $data['discount_pct'], $this->config->feeCents());
        $this->assertLowEarningConfirmed($request, $quote);

        $box = new BoxOffer;
        $box->seller_id = $seller->id;
        $box->offer_date = $this->today();
        $box->box_type_id = $data['box_type_id'];
        $box->category_id = $data['category_id'];
        $box->setPlantAttributes($data['attributes'] ?? []);
        $box->description = trim($data['description']);
        $box->list_value_cents = $quote['list_value_cents'];
        $box->discount_pct = $quote['discount_pct'];
        $box->price_cents = $quote['price_cents'];
        $box->fee_cents = $quote['fee_cents']; // istantanea: una modifica futura della tariffa non cambia questa box
        $box->stock_total = $data['quantity'];
        $box->stock_available = $data['quantity'];
        $box->status = BoxOffer::STATUS_ACTIVE;
        $box->save();

        return response()->json(BoxResource::make($box), 201);
    }

    /**
     * Modifica una box di oggi. Descrizione, tipo, categoria e attributi sempre; prezzo e valore solo finché
     * non c'è stata nessuna vendita (CA3); la quantità può solo diminuire, fino a quanto già venduto (CA2).
     */
    public function update(Request $request, int $box): JsonResponse
    {
        $seller = $this->seller($request);
        $this->assertCanPublish($seller);
        $data = $this->validated($request, creating: false);

        return DB::transaction(function () use ($request, $seller, $box, $data) {
            // Blocco la riga: un acquisto simultaneo non deve far sballare i conti dello stock
            $offer = BoxOffer::where('seller_id', $seller->id)->lockForUpdate()->find($box)
                ?? ApiError::throw('NOT_FOUND', 'Box non trovata.', 404);
            $this->assertToday($offer);

            $sold = $offer->sold();
            $priceChange = array_key_exists('list_value_cents', $data) || array_key_exists('discount_pct', $data);
            $list = $data['list_value_cents'] ?? $offer->list_value_cents;
            $discount = $data['discount_pct'] ?? $offer->discount_pct;

            if ($priceChange && ($list !== $offer->list_value_cents || $discount !== $offer->discount_pct)) {
                if ($sold > 0) {
                    ApiError::throw('PRICE_LOCKED', 'Dopo la prima vendita il valore e lo sconto non si possono più cambiare.', 409);
                }
                $quote = PriceCalculator::quote($list, $discount, $this->config->feeCents());
                $this->assertLowEarningConfirmed($request, $quote);
                $offer->list_value_cents = $quote['list_value_cents'];
                $offer->discount_pct = $quote['discount_pct'];
                $offer->price_cents = $quote['price_cents'];
                $offer->fee_cents = $quote['fee_cents'];
            }

            if (array_key_exists('quantity', $data)) {
                $this->applyQuantity($offer, $data['quantity'], $sold);
            }

            foreach (['box_type_id', 'category_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $offer->{$field} = $data[$field];
                }
            }
            if (array_key_exists('attributes', $data)) {
                $offer->setPlantAttributes($data['attributes']);
            }
            if (array_key_exists('description', $data)) {
                $offer->description = trim($data['description']);
            }

            $offer->save();

            return response()->json(BoxResource::make($offer));
        });
    }

    /** Elimina una box senza vendite. Con ordini si può solo ridurre la quantità a quanto già venduto (US-V10). */
    public function destroy(Request $request, int $box): JsonResponse
    {
        $seller = $this->seller($request);

        return DB::transaction(function () use ($seller, $box) {
            $offer = BoxOffer::where('seller_id', $seller->id)->lockForUpdate()->find($box)
                ?? ApiError::throw('NOT_FOUND', 'Box non trovata.', 404);

            if ($offer->sold() > 0) {
                ApiError::throw('BOX_HAS_ORDERS', 'Ci sono ordini per questa box: non si può eliminare. Puoi ridurre la quantità a quanto già venduto.', 409);
            }

            $offer->delete();

            return response()->json(null, 204);
        });
    }

    private function applyQuantity(BoxOffer $offer, int $quantity, int $sold): void
    {
        if ($quantity > $offer->stock_total) {
            ApiError::throw('QUANTITY_CANNOT_INCREASE', 'La quantità può solo diminuire.', 422);
        }
        if ($quantity < $sold) {
            ApiError::throw('QUANTITY_BELOW_SOLD', "Ne hai già vendute {$sold}: la quantità non può essere inferiore.", 422);
        }

        $offer->stock_total = $quantity;
        $offer->stock_available = $quantity - $sold;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'box_type_id' => [$required, 'integer', Rule::exists('box_types', 'id')],
            'category_id' => [$required, 'integer', Rule::exists('categories', 'id')],
            'attributes' => ['sometimes', 'array', 'max:10'],
            'attributes.*' => ['string', Rule::exists('plant_attributes', 'code')],
            'description' => [$required, 'string', 'min:5', 'max:1000'],
            'list_value_cents' => [$required, 'integer', 'min:1', 'max:1000000'],
            'discount_pct' => [$required, 'integer', 'between:'.$this->config->discountMin().','.$this->config->discountMax()],
            'quantity' => [$required, 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'confirm_low_earning' => ['sometimes', 'boolean'],
        ]);
    }

    /** Prezzo ≤ tariffa: il guadagno è nullo o negativo e il venditore deve confermare di averlo capito. */
    private function assertLowEarningConfirmed(Request $request, array $quote): void
    {
        if ($quote['earning_cents'] > 0 || $request->boolean('confirm_low_earning')) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'code' => 'LOW_EARNING_CONFIRMATION_REQUIRED',
            'message' => 'Con questo prezzo il tuo guadagno è nullo o negativo. Conferma per pubblicare comunque.',
            'quote' => $quote,
        ], 409));
    }

    private function assertCanPublish(Seller $seller): void
    {
        if (! $seller->canPublish()) {
            ApiError::throw('SELLER_NOT_READY', 'Per gestire le box serve un\'attività approvata e il conto di pagamento collegato.', 403);
        }
    }

    private function assertToday(BoxOffer $offer): void
    {
        if ($offer->offer_date->toDateString() !== $this->today()) {
            ApiError::throw('BOX_NOT_EDITABLE', 'Le box si modificano solo nel giorno di vendita.', 409);
        }
    }

    private function seller(Request $request): Seller
    {
        return $request->user()->seller
            ?? ApiError::throw('PROFILE_REQUIRED', 'Compila prima i dati dell\'attività.', 409);
    }

    private function ownBox(Request $request, int $id): BoxOffer
    {
        return BoxOffer::where('seller_id', $this->seller($request)->id)->find($id)
            ?? ApiError::throw('NOT_FOUND', 'Box non trovata.', 404);
    }

    /** "Oggi" nel fuso di business (Europe/Rome), con l'orologio simulato in sviluppo. */
    private function today(): string
    {
        return $this->clock->todayLocal()->toDateString();
    }
}

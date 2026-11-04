<?php

namespace App\Http\Resources;

use App\Models\BoxOffer;
use Illuminate\Support\Facades\DB;

/** Come il venditore vede una sua box: prezzo, tariffa e guadagno netto (anche se negativo). */
final class BoxResource
{
    /** @return array<string, mixed> */
    public static function make(BoxOffer $box): array
    {
        $type = DB::table('box_types')->find($box->box_type_id);
        $category = DB::table('categories')->find($box->category_id);
        $earning = $box->price_cents - $box->fee_cents;

        return [
            'id' => $box->id,
            'offer_date' => $box->offer_date->toDateString(),
            'box_type' => ['id' => $type->id, 'code' => $type->code, 'label' => $type->label],
            'category' => ['id' => $category->id, 'code' => $category->code, 'label' => $category->label],
            'attributes' => $box->plantAttributes(),
            'description' => $box->description,
            'list_value_cents' => $box->list_value_cents,
            'discount_pct' => $box->discount_pct,
            'price_cents' => $box->price_cents,
            'fee_cents' => $box->fee_cents,
            'earning_cents' => $earning,
            'earning_not_positive' => $earning <= 0,
            'stock_total' => $box->stock_total,
            'stock_available' => $box->stock_available,
            'sold' => $box->sold(),
            'sold_out' => $box->stock_available === 0,
            // Dopo la prima vendita prezzo e valore non si cambiano più (US-V09 CA3)
            'price_locked' => $box->sold() > 0,
            'status' => $box->status,
        ];
    }
}

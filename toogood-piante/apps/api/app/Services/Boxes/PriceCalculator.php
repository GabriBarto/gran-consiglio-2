<?php

namespace App\Services\Boxes;

/**
 * Formula del prezzo (RB-04, RB-05), in centesimi interi: niente numeri decimali per i soldi.
 *
 *   prezzo   = valore di listino × (100 − sconto) / 100, arrotondato all'euro-centesimo (mezzo verso l'alto)
 *   guadagno = prezzo − tariffa      (può essere nullo o negativo)
 *
 * I casi di prova condivisi con l'app stanno in contracts/price-cases.json.
 */
final class PriceCalculator
{
    /** @return array{list_value_cents: int, discount_pct: int, price_cents: int, fee_cents: int, earning_cents: int} */
    public static function quote(int $listValueCents, int $discountPct, int $feeCents): array
    {
        $price = intdiv($listValueCents * (100 - $discountPct) + 50, 100);

        return [
            'list_value_cents' => $listValueCents,
            'discount_pct' => $discountPct,
            'price_cents' => $price,
            'fee_cents' => $feeCents,
            'earning_cents' => $price - $feeCents,
        ];
    }
}

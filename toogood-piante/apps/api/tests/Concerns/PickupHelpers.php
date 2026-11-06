<?php

namespace Tests\Concerns;

use App\Models\Seller;
use App\Models\User;
use Carbon\Carbon;

/**
 * Ordini già pagati e ritirati, per i test di recensioni e reclami.
 * Ordine alle 10:00 a Roma (08:00 UTC); ritiro 17:00–19:00 a Roma (15:00–17:00 UTC).
 */
trait PickupHelpers
{
    protected const ORDERED_AT = '2026-06-15 08:00:00';

    protected const SCAN_AT = '2026-06-15 16:00:00';

    /** Un ordine pagato da $price centesimi a box (la tariffa è 150), con quantità 1. */
    protected function paidOrder(User $customer, Seller $shop, int $price = 700): array
    {
        Carbon::setTestNow(self::ORDERED_AT);
        $box = $this->makeBox($shop, ['stock_total' => 50, 'stock_available' => 50, 'price_cents' => $price]);
        $order = $this->placeOrder($customer, [$box->id => 1]);
        $this->actingAsUser($customer)->postJson("/api/orders/{$order['id']}/simulate-payment")->assertOk()->assertJsonPath('status', 'PAID');

        return $order;
    }

    /** Come `paidOrder`, poi il venditore scansiona il QR durante la fascia di ritiro (16:00 UTC). */
    protected function pickedUpOrder(User $customer, Seller $shop, int $price = 700): array
    {
        $order = $this->paidOrder($customer, $shop, $price);
        $ticket = $this->actingAsUser($customer)->getJson("/api/orders/{$order['id']}/pickup")->assertOk()->json();

        Carbon::setTestNow(self::SCAN_AT);
        $this->actingAsUser($shop->user)->postJson('/api/seller/pickup/scan', ['qr' => $ticket['qr']])->assertOk();

        return $order;
    }
}

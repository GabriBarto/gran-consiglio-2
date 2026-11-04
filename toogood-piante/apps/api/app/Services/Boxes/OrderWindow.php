<?php

namespace App\Services\Boxes;

use App\Models\Seller;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Fino a quando si può ordinare (RB-16): fino a N ore prima della fine della fascia di ritiro di oggi
 * (N = parametro `order_cutoff_hours`, oggi 2). Orari nel fuso Europe/Rome, con l'orologio simulato in sviluppo.
 */
final class OrderWindow
{
    public function __construct(private readonly Clock $clock, private readonly BusinessConfig $config) {}

    /** L'orario limite per ordinare oggi dal negozio. */
    public function deadline(Seller $seller): CarbonImmutable
    {
        return $this->clock->todayLocal()
            ->setTimeFromTimeString((string) $seller->pickup_to)
            ->subHours($this->config->int('order_cutoff_hours'));
    }

    public function isOpen(Seller $seller): bool
    {
        return $this->clock->now()->lt($this->deadline($seller));
    }
}

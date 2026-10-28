<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Unica fonte dell'ora per la logica di business (fasce di ritiro, scadenze, payout).
 * In modalità "fake" l'ora può essere spostata in avanti senza aspettare.
 * Rispetta anche Carbon::setTestNow() nei test.
 */
final class Clock
{
    private const OFFSET_KEY = 'clock.offset_seconds';

    public function now(): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return $this->isFake() ? $now->addSeconds($this->offset()) : $now;
    }

    public function nowLocal(): CarbonImmutable
    {
        return $this->now()->setTimezone(config('toogood.timezone'));
    }

    /** Inizio del giorno corrente nel fuso di business (Europe/Rome). */
    public function todayLocal(): CarbonImmutable
    {
        return $this->nowLocal()->startOfDay();
    }

    public function isFake(): bool
    {
        return config('toogood.clock.mode') === 'fake';
    }

    public function offset(): int
    {
        return (int) Cache::get(self::OFFSET_KEY, 0);
    }

    public function advance(int $seconds): void
    {
        $this->assertFake();
        Cache::forever(self::OFFSET_KEY, $this->offset() + $seconds);
    }

    public function reset(): void
    {
        $this->assertFake();
        Cache::forget(self::OFFSET_KEY);
    }

    private function assertFake(): void
    {
        if (! $this->isFake()) {
            throw new LogicException('L\'orologio si può spostare solo con CLOCK_MODE=fake.');
        }
    }
}

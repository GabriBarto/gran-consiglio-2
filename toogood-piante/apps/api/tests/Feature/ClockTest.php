<?php

namespace Tests\Feature;

use App\Support\Clock;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\TestCase;

class ClockTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_in_modalita_reale_l_ora_non_viene_spostata(): void
    {
        config(['toogood.clock.mode' => 'real']);
        CarbonImmutable::setTestNow('2026-10-21 10:00:00');

        $this->assertSame('2026-10-21 10:00:00', app(Clock::class)->now()->toDateTimeString());
    }

    public function test_in_modalita_fake_si_puo_avanzare_e_riportare_indietro(): void
    {
        config(['toogood.clock.mode' => 'fake']);
        CarbonImmutable::setTestNow('2026-10-21 10:00:00');
        $clock = app(Clock::class);

        $clock->advance(24 * 3600);
        $this->assertSame('2026-10-22 10:00:00', $clock->now()->toDateTimeString());

        $clock->reset();
        $this->assertSame('2026-10-21 10:00:00', $clock->now()->toDateTimeString());
    }

    public function test_avanzare_in_modalita_reale_e_vietato(): void
    {
        config(['toogood.clock.mode' => 'real']);

        $this->expectException(LogicException::class);
        app(Clock::class)->advance(60);
    }

    public function test_oggi_si_calcola_nel_fuso_di_roma(): void
    {
        config(['toogood.clock.mode' => 'real']);
        // 23:30 UTC del 20 ottobre = 01:30 del 21 ottobre a Roma (ora legale, UTC+2)
        CarbonImmutable::setTestNow('2026-10-20 23:30:00');

        $this->assertSame('2026-10-21', app(Clock::class)->todayLocal()->toDateString());
    }

    public function test_il_cambio_di_ora_legale_di_fine_ottobre_e_gestito(): void
    {
        config(['toogood.clock.mode' => 'real']);
        // Il 25 ottobre 2026 l'ora legale finisce: alle 01:00 UTC Roma passa da UTC+2 a UTC+1
        CarbonImmutable::setTestNow('2026-10-25 00:30:00');
        $this->assertSame('02:30', app(Clock::class)->nowLocal()->format('H:i'));

        CarbonImmutable::setTestNow('2026-10-25 01:30:00');
        $this->assertSame('02:30', app(Clock::class)->nowLocal()->format('H:i'));
    }
}

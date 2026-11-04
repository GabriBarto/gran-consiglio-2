<?php

namespace Tests\Unit;

use App\Services\Boxes\PriceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Esegue i casi condivisi con l'app (contracts/price-cases.json). */
class PriceCalculatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $file = dirname(__DIR__, 4).'/contracts/price-cases.json';
        if (! is_file($file)) {
            $file = '/var/contracts/price-cases.json'; // percorso nel container (vedi docker-compose.yml)
        }

        $cases = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['cases'];

        return array_combine(array_column($cases, 'name'), array_map(fn ($c) => [$c], $cases));
    }

    #[DataProvider('cases')]
    public function test_la_formula_rispetta_i_casi_condivisi(array $case): void
    {
        $quote = PriceCalculator::quote($case['list_value_cents'], $case['discount_pct'], $case['fee_cents']);

        $this->assertSame($case['price_cents'], $quote['price_cents']);
        $this->assertSame($case['earning_cents'], $quote['earning_cents']);
    }
}

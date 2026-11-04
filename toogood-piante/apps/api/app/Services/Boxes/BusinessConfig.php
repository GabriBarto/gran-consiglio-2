<?php

namespace App\Services\Boxes;

use Illuminate\Support\Facades\DB;

/**
 * Parametri di business modificabili senza cambiare il codice (tabella config_params):
 * tariffa, limiti dello sconto, ecc. Nell'MVP si cambiano nel database.
 */
final class BusinessConfig
{
    /** Legge sempre dal database (poche righe): così un valore cambiato ha effetto subito, senza cache da svuotare. */
    public function int(string $key): int
    {
        $value = DB::table('config_params')->where('key', $key)->value('value');

        if ($value === null) {
            throw new \RuntimeException("Parametro di configurazione mancante: {$key} (eseguire il seeder dei dati fissi).");
        }

        return (int) $value;
    }

    public function feeCents(): int
    {
        return $this->int('fee_cents');
    }

    public function discountMin(): int
    {
        return $this->int('discount_min_pct');
    }

    public function discountMax(): int
    {
        return $this->int('discount_max_pct');
    }
}

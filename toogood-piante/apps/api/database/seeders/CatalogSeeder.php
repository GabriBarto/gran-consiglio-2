<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dati fissi dell'app: tipologie, categorie, attributi delle piante e parametri di business.
 * Si può rieseguire senza creare duplicati.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLabeled('box_types', [
            ['SMALL', 'Box piccola (1–2 piante)'],
            ['MEDIUM', 'Box media (3–4 piante)'],
            ['LARGE', 'Box grande (5 o più piante)'],
        ]);

        $this->seedLabeled('categories', [
            ['SUCCULENTS', 'Piante grasse e succulente'],
            ['FLOWERING', 'Piante da fiore'],
            ['HOUSEPLANTS', 'Piante verdi da appartamento'],
            ['HERBS', 'Aromatiche'],
            ['OUTDOOR', 'Piante da esterno'],
            ['SEEDLINGS', 'Piantine da orto'],
            ['MIXED', 'Mista'],
        ]);

        $attributes = [
            ['INDOOR', 'Da interno', 'LOCATION'],
            ['OUTDOOR', 'Da esterno', 'LOCATION'],
            ['TOXIC_PETS', 'Tossica per animali domestici', 'TOXICITY'],
            ['TOXIC_CHILDREN', 'Tossica per i bambini', 'TOXICITY'],
            ['FULL_SUN', 'Pieno sole', 'LIGHT'],
            ['PARTIAL_SHADE', 'Mezz\'ombra', 'LIGHT'],
            ['SHADE', 'Ombra', 'LIGHT'],
            ['EASY_CARE', 'Cura facile', 'CARE'],
            ['MEDIUM_CARE', 'Cura media', 'CARE'],
            ['DEMANDING_CARE', 'Cura impegnativa', 'CARE'],
        ];
        foreach ($attributes as $i => [$code, $label, $group]) {
            DB::table('plant_attributes')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'group' => $group, 'sort_order' => $i + 1],
            );
        }

        $params = [
            ['fee_cents', '150', 'Tariffa di servizio per ogni box venduta, in centesimi'],
            ['discount_min_pct', '60', 'Sconto minimo sul valore di listino (%)'],
            ['discount_max_pct', '70', 'Sconto massimo sul valore di listino (%)'],
            ['order_cutoff_hours', '2', 'Si può ordinare fino a N ore prima della fine della fascia di ritiro'],
            ['payout_delay_hours', '24', 'Ore dopo la fine della fascia di ritiro prima di pagare il venditore'],
            ['complaint_window_hours', '24', 'Ore entro cui aprire un reclamo (dal ritiro, o dalla fine fascia se non consegnata)'],
            ['reservation_minutes', '10', 'Minuti di prenotazione dello stock durante il pagamento'],
        ];
        foreach ($params as [$key, $value, $description]) {
            // Non sovrascrive un valore già modificato a mano
            DB::table('config_params')->insertOrIgnore([
                'key' => $key, 'value' => $value, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<int, array{0: string, 1: string}> $rows */
    private function seedLabeled(string $table, array $rows): void
    {
        foreach ($rows as $i => [$code, $label]) {
            DB::table($table)->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'sort_order' => $i + 1],
            );
        }
    }
}

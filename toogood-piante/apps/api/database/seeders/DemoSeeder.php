<?php

namespace Database\Seeders;

use App\Models\BoxOffer;
use App\Models\Seller;
use App\Models\User;
use App\Services\Boxes\BusinessConfig;
use App\Services\Boxes\PriceCalculator;
use App\Support\Clock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dati di prova per la demo: due clienti, tre negozi approvati con il conto collegato e le loro box di oggi, più un
 * negozio sospeso. Non fa parte di `db:seed` (che crea solo i dati fissi e l'admin): si lancia a parte e si può
 * rilanciare ogni mattina per avere box fresche, senza duplicare nulla.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Tutti gli account hanno la stessa password; l'accesso chiede comunque il codice via email (Mailpit) la prima volta.
 * Rifiuta di girare in produzione.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Password1';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder non gira in produzione.');

            return;
        }
        if (! DB::table('box_types')->exists()) {
            $this->call(CatalogSeeder::class);
        }

        foreach ([['anna', 'anna.demo@toogood.test'], ['luca', 'luca.demo@toogood.test']] as [$username, $email]) {
            $this->user($username, $email, User::ROLE_CUSTOMER);
        }

        $shops = [
            // nome, utente, email, P.IVA, indirizzo, città, lat, lng, apertura, chiusura, ritiro da, ritiro a, stato, box
            ['Vivaio Rossi', 'vivaiorossi', 'rossi.demo@toogood.test', 'IT10000000011', 'Via Rizzoli 5', 'Bologna', 44.494, 11.343, '08:00', '19:30', '17:00', '19:00', Seller::STATUS_APPROVED, [
                ['MEDIUM', 'SUCCULENTS', ['INDOOR', 'EASY_CARE'], 'Tre piante grasse in vasetto', 2000, 65, 6],
                ['LARGE', 'FLOWERING', ['OUTDOOR', 'FULL_SUN'], 'Cinque piante da fiore di stagione', 3500, 62, 4],
                ['SMALL', 'HERBS', ['INDOOR'], 'Basilico e rosmarino', 800, 70, 8],
            ]],
            ['Piante & Co', 'pianteeco', 'pianteco.demo@toogood.test', 'IT20000000012', 'Via Rossini 10', 'Pesaro', 43.9099, 12.9136, '09:00', '19:00', '16:30', '18:30', Seller::STATUS_APPROVED, [
                ['MEDIUM', 'HOUSEPLANTS', ['INDOOR', 'PARTIAL_SHADE'], 'Pothos, ficus e sansevieria', 2400, 66, 5],
                ['SMALL', 'SEEDLINGS', ['OUTDOOR'], 'Piantine da orto: pomodori e zucchine', 900, 60, 10],
            ]],
            ['Verde Milano', 'verdemilano', 'verde.demo@toogood.test', 'IT30000000013', 'Via Torino 10', 'Milano', 45.4642, 9.19, '08:30', '20:00', '17:30', '19:30', Seller::STATUS_APPROVED, [
                ['LARGE', 'MIXED', ['INDOOR', 'OUTDOOR'], 'Mista: aromatiche, grasse e una pianta da fiore', 4000, 68, 3],
            ]],
            // Sospeso: serve a mostrare che non compare nella ricerca e non può pubblicare
            ['Giardino Sospeso', 'giardinosospeso', 'sospeso.demo@toogood.test', 'IT40000000014', 'Via del Corso 1', 'Roma', 41.9028, 12.4964, '08:00', '19:00', '17:00', '19:00', Seller::STATUS_SUSPENDED, []],
        ];

        $today = app(Clock::class)->todayLocal()->toDateString();
        $fee = app(BusinessConfig::class)->feeCents();

        foreach ($shops as [$name, $username, $email, $vat, $address, $city, $lat, $lng, $opens, $closes, $from, $to, $status, $boxes]) {
            $user = $this->user($username, $email, User::ROLE_SELLER);

            $seller = Seller::firstOrNew(['user_id' => $user->id]);
            $seller->fill([
                'business_name' => $name, 'vat_number' => $vat, 'phone' => '+39 051 0000000',
                'address' => "$address, $city", 'city' => $city, 'latitude' => $lat, 'longitude' => $lng,
                'opens_at' => $opens, 'closes_at' => $closes, 'pickup_from' => $from, 'pickup_to' => $to,
            ]);
            $seller->forceFill([
                'verification_status' => $status,
                'verification_note' => $status === Seller::STATUS_SUSPENDED ? 'Dimostrazione: negozio sospeso dall\'admin.' : null,
                'vat_check_status' => Seller::VAT_VALID,
                'stripe_account_id' => 'acct_demo_'.$username,
                'stripe_onboarded_at' => now(),
                'verified_at' => now(),
            ])->save();

            foreach ($boxes as [$type, $category, $attributes, $description, $listValue, $discount, $stock]) {
                $this->box($seller, $today, $fee, $type, $category, $attributes, $description, $listValue, $discount, $stock);
            }
        }

        $this->command?->info('Dati di prova pronti. Password di tutti gli account: '.self::PASSWORD);
        $this->command?->table(['Ruolo', 'Email'], [
            ['Cliente', 'anna.demo@toogood.test'], ['Cliente', 'luca.demo@toogood.test'],
            ['Venditore (Bologna)', 'rossi.demo@toogood.test'], ['Venditore (Pesaro)', 'pianteco.demo@toogood.test'],
            ['Venditore (Milano)', 'verde.demo@toogood.test'], ['Venditore sospeso (Roma)', 'sospeso.demo@toogood.test'],
        ]);
    }

    private function user(string $username, string $email, string $role): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->username = $username;
        $user->password = self::PASSWORD;
        $user->role = $role;
        $user->status = User::STATUS_ACTIVE;
        $user->email_verified_at = now();
        $user->must_change_password = false;
        $user->save();

        return $user;
    }

    /** @param list<string> $attributes */
    private function box(Seller $seller, string $today, int $fee, string $type, string $category, array $attributes, string $description, int $listValue, int $discount, int $stock): void
    {
        // Già presente per oggi: non si duplica e non si tocca lo stock di una prova in corso
        if (BoxOffer::where('seller_id', $seller->id)->whereDate('offer_date', $today)->where('description', $description)->exists()) {
            return;
        }
        $quote = PriceCalculator::quote($listValue, $discount, $fee);

        $box = new BoxOffer;
        $box->seller_id = $seller->id;
        $box->offer_date = $today;
        $box->box_type_id = DB::table('box_types')->where('code', $type)->value('id');
        $box->category_id = DB::table('categories')->where('code', $category)->value('id');
        $box->setPlantAttributes($attributes);
        $box->description = $description;
        $box->list_value_cents = $quote['list_value_cents'];
        $box->discount_pct = $quote['discount_pct'];
        $box->price_cents = $quote['price_cents'];
        $box->fee_cents = $quote['fee_cents'];
        $box->stock_total = $stock;
        $box->stock_available = $stock;
        $box->status = BoxOffer::STATUS_ACTIVE;
        $box->save();
    }
}

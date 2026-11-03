<?php

namespace Tests\Concerns;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** Servizi esterni finti (geocoding e P.IVA) e scorciatoie per creare venditori nei test. */
trait SellerHelpers
{
    protected function fakeExternalServices(bool $geocodingDown = false): void
    {
        config([
            'toogood.services.geocoding_url' => 'http://geo.test/geocode',
            'toogood.services.vies_url' => 'http://vies.test/check',
        ]);
        Storage::fake('s3');

        Http::fake([
            'geo.test/*' => function (Request $request) use ($geocodingDown) {
                if ($geocodingDown) {
                    throw new ConnectionException('timeout');
                }
                $address = (string) $request['address'];

                return str_ends_with($address, 'Milano')
                    ? Http::response(['found' => true, 'lat' => 45.4642, 'lng' => 9.19, 'city' => 'Milano'])
                    : Http::response(['found' => false]);
            },
            'vies.test/check/IT12345678901' => Http::response(['valid' => true]),
            'vies.test/check/IT22222222222' => Http::response(['valid' => true]),
            'vies.test/check/IT99999999999' => Http::response(['error' => 'giù'], 503),
            'vies.test/check/*' => Http::response(['valid' => false]),
        ]);
    }

    /** @return array<string, mixed> */
    protected function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Vivaio Rossi S.r.l.',
            'vat_number' => 'IT12345678901',
            'phone' => '+39 02 1234567',
            'address' => 'Via Roma 1, Milano',
            'opens_at' => '08:00',
            'closes_at' => '19:00',
            'pickup_from' => '17:00',
            'pickup_to' => '19:00',
        ], $overrides);
    }

    protected function actingAsUser(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    /** Un venditore con il profilo già compilato, nello stato indicato. */
    protected function sellerWithProfile(string $status = Seller::STATUS_PENDING, array $userState = [], string $vat = 'IT22222222222'): Seller
    {
        $user = User::factory()->seller()->create($userState);

        $seller = new Seller($this->profilePayload(['vat_number' => $vat]) + ['city' => 'Milano', 'latitude' => 45.4642, 'longitude' => 9.19]);
        $seller->user_id = $user->id;
        $seller->verification_status = $status;
        $seller->vat_check_status = Seller::VAT_VALID;
        $seller->save();

        return $seller;
    }
}

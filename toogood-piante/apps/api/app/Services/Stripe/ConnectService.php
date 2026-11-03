<?php

namespace App\Services\Stripe;

use App\Models\Seller;
use Stripe\StripeClient;

/**
 * Onboarding Stripe Connect del venditore. Isolato in una classe per poterlo sostituire nei test
 * e per puntare a stripe-mock in sviluppo (STRIPE_API_BASE) o alle API di test di Stripe (vuoto).
 */
class ConnectService
{
    private function client(): StripeClient
    {
        $config = ['api_key' => config('toogood.stripe.secret')];
        if ($base = config('toogood.stripe.api_base')) {
            $config['api_base'] = $base;
        }

        return new StripeClient($config);
    }

    /** Crea l'account Connect del venditore e restituisce il suo id. */
    public function createAccount(Seller $seller): string
    {
        $account = $this->client()->accounts->create([
            'type' => 'express',
            'country' => 'IT',
            'email' => $seller->user->email,
            'business_type' => 'company',
            'capabilities' => ['transfers' => ['requested' => true]],
            'metadata' => ['seller_id' => (string) $seller->id],
        ]);

        return $account->id;
    }

    /** Indirizzo (monouso) dove il venditore completa i dati su Stripe. */
    public function onboardingUrl(string $accountId): string
    {
        $link = $this->client()->accountLinks->create([
            'account' => $accountId,
            'type' => 'account_onboarding',
            'refresh_url' => url('/stripe/refresh'),
            'return_url' => url('/stripe/return'),
        ]);

        return $link->url;
    }

    /**
     * L'onboarding è completo quando Stripe ha attivato la capacità "transfers": è l'unica cosa che ci serve,
     * perché alla piattaforma basta poter inviare il guadagno al venditore (RB-11). Con Stripe vero la capacità
     * si attiva solo dopo che il venditore ha consegnato i dati; stripe-mock la restituisce già attiva.
     */
    public function isOnboarded(string $accountId): bool
    {
        $account = $this->client()->accounts->retrieve($accountId);

        return ($account->capabilities->transfers ?? null) === 'active';
    }
}

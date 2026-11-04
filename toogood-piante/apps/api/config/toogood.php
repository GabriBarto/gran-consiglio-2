<?php

return [

    /*
    | Fuso orario di business: "oggi" e le fasce di ritiro si calcolano qui.
    | Le date nel database restano sempre in UTC.
    */
    'timezone' => 'Europe/Rome',

    'clock' => [
        // "real" (produzione) oppure "fake" (sviluppo e test: permette di far avanzare il tempo)
        'mode' => env('CLOCK_MODE', 'real'),
    ],

    // Credenziali iniziali dell'admin (solo per il seeder: al primo accesso va cambiata la password)
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'initial_password' => env('ADMIN_INITIAL_PASSWORD'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET', 'sk_test_mock'),
        // In sviluppo e nei test punta a stripe-mock; vuoto = API reali di Stripe (modalità test)
        'api_base' => env('STRIPE_API_BASE'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', 'whsec_test'),
        // Chiave pubblica per l'app (Stripe vero); con stripe-mock resta vuota
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
    ],

    'payments' => [
        'currency' => 'eur',
        // Stripe non accetta pagamenti sotto i 0,50 €
        'min_amount_cents' => 50,
        // Pagamento di prova senza carta (solo sviluppo): attivo di default con stripe-mock, mai con le API vere di Stripe
        'simulated' => (bool) env('PAYMENTS_SIMULATED', ! empty(env('STRIPE_API_BASE'))),
    ],

    'services' => [
        // "nominatim" (OpenStreetMap, indirizzi reali) oppure "simulated" (WireMock, solo test e prove offline)
        'geocoding_driver' => env('GEOCODING_DRIVER', 'nominatim'),
        'nominatim_url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'geocoding_user_agent' => env('GEOCODING_USER_AGENT', 'TooGoodPiante/0.1 (progetto di studio)'),
        'geocoding_url' => env('GEOCODING_URL'),
        'vies_url' => env('VIES_URL'),
        'mailpit_api_url' => env('MAILPIT_API_URL'),
    ],

];

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

    'stripe' => [
        'secret' => env('STRIPE_SECRET', 'sk_test_mock'),
        // In sviluppo e nei test punta a stripe-mock; vuoto = API reali di Stripe (modalità test)
        'api_base' => env('STRIPE_API_BASE'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', 'whsec_test'),
    ],

    'services' => [
        'geocoding_url' => env('GEOCODING_URL'),
        'vies_url' => env('VIES_URL'),
        'mailpit_api_url' => env('MAILPIT_API_URL'),
    ],

];

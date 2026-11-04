<?php

// Il pannello admin web (Vite, porta 5173) è un'origine diversa dall'API: senza questa
// configurazione il browser blocca le richieste. L'app mobile non ne ha bisogno.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173'
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 600,
    // Il pannello usa token Bearer, non cookie.
    'supports_credentials' => false,
];

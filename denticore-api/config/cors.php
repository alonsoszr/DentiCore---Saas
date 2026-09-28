<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // SDD §1.5 IE-01, §1.7 (DD-44, RNF-096): lista explícita de orígenes, sin comodín.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Cada origen como patrón exacto: con un solo origen literal, php-cors enviaría
    // Access-Control-Allow-Origin también a orígenes no listados.
    'allowed_origins' => [],

    'allowed_origins_patterns' => array_map(
        fn (string $origin): string => '#^'.preg_quote($origin, '#').'$#',
        array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))))),
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Correlation-Id', 'Retry-After', 'RateLimit-Limit', 'RateLimit-Remaining', 'RateLimit-Reset'],

    'max_age' => 0,

    'supports_credentials' => false,

];

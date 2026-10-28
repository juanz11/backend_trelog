<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the origins, methods and headers allowed for cross-origin
    | requests from the frontend.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [config('app.frontend_url')],

    'allowed_origins_patterns' => [
        // Local development
        '/^http:\/\/localhost:\d+$/',
        '/^http:\/\/127\.0\.0\.1:\d+$/',
        // Production: HTTPS only, apex or single-level subdomains
        '/^https:\/\/([a-z0-9-]+\.)?tr3slog\.com$/',
        '/^https:\/\/([a-z0-9-]+\.)?termocontroljb\.com$/',
        '/^https:\/\/([a-z0-9-]+\.)?tr3slog\.julls\.net$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

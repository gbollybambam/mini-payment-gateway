<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // STRICTLY define your frontend URLs. Never use '*' when supports_credentials is true.
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Essential for Sanctum SPA authentication
];

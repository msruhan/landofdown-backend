<?php

$defaultOrigins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:3000',
];

$frontendUrl = env('FRONTEND_URL');
if (is_string($frontendUrl) && trim($frontendUrl) !== '') {
    $defaultOrigins[] = trim($frontendUrl);
}

$corsOrigins = array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
);

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => count($corsOrigins) > 0 ? array_values($corsOrigins) : array_values(array_unique($defaultOrigins)),

    'allowed_origins_patterns' => [
        '#^https://.*\.ngrok-free\.dev$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

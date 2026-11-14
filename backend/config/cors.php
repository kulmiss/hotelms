<?php

/*
 * Cross-origin requests (SECURITY_REPORT L3): only the app's own address may call the API from a browser,
 * with the sign-in cookie. The web app and the API live on the same domain, so in normal use no
 * cross-origin request happens at all; this only matters for a separate front-end address (FRONTEND_URL).
 */
$origins = array_values(array_unique(array_filter(array_map(
    fn ($url) => $url ? rtrim((string) $url, '/') : null,
    [env('APP_URL'), env('FRONTEND_URL')],
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'X-Requested-With', 'X-Branch-Id', 'Idempotency-Key', 'Authorization'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => true,
];

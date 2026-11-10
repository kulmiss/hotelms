<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Only for a server behind a proxy or CDN (e.g. Cloudflare, a load balancer): TRUSTED_PROXIES lists the proxy
 * addresses (comma separated, CIDR allowed, or "*" when the server can only be reached through the proxy).
 * Then the visitor's real IP (sign-in limits, activity log) and HTTPS come from the proxy's X-Forwarded-* headers.
 * Empty (the default): nobody is trusted, so a visitor cannot fake their IP address with such a header.
 */
class TrustedProxies
{
    public static function apply(?string $setting): void
    {
        $setting = trim((string) $setting);
        if ($setting === '') {
            return;
        }
        TrustProxies::at($setting === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $setting)))));
    }
}

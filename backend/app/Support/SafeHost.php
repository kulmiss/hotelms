<?php

namespace App\Support;

/**
 * Server addresses typed in Super Admin (the mail server) must be on the internet, not inside the server's own
 * network (SECURITY_REPORT L5 – SSRF): no 127.0.0.1 / localhost, 10.x, 172.16–31.x, 192.168.x, link-local or
 * reserved addresses, also not when a name resolves to one. MAIL_ALLOW_PRIVATE_HOST=true in .env allows them
 * (a mail server on the same machine).
 */
class SafeHost
{
    public static function allowed(string $host): bool
    {
        if (config('hotelms.mail_allow_private_host')) {
            return true;
        }

        return self::isPublic($host);
    }

    /** On the internet (calendar links: always, no setting allows the server's own network). */
    public static function isPublic(string $host): bool
    {
        $host = strtolower(trim($host, ' .[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (filter_var($host, FILTER_VALIDATE_IP) === false && checkdnsrr($host, 'AAAA')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                $ips[] = $r['ipv6'] ?? '';
            }
        }
        if ($ips === []) {
            return true; // not found now (no internet, a new name): checked again each time it is used
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Support;

/**
 * Time-based one-time codes (RFC 6238) for authenticator apps such as
 * Google Authenticator or Microsoft Authenticator: 6 digits, 30 seconds, SHA-1.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PERIOD = 30;

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code for a time step (step = unix time / 30). */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The time step the code belongs to (allowing one step of clock drift either way), or null.
     * The caller stores the step so a code cannot be used twice.
     */
    public static function match(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\D+/', '', $code);
        if (strlen((string) $code) !== 6) {
            return null;
        }
        $now = intdiv($time ?? time(), self::PERIOD);
        foreach ([0, -1, 1] as $drift) {
            if (hash_equals(self::code($secret, $now + $drift), (string) $code)) {
                return $now + $drift;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$account);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&digits=6&period=30';
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=]+/', '', $text));
        $bits = '';
        foreach (str_split($text) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                continue;
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}

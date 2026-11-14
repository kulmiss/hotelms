<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * The rules for a NEW password (SECURITY_REPORT M2, owner 14 Nov): at least 10 characters with letters and
 * numbers, and not one of the 100,000 most common passwords (a list kept in the app, checked without the
 * internet). Existing passwords keep working; a weak one only gets a "please change it" note at sign-in.
 */
class PasswordRules
{
    public const MIN = 10;

    /** @var array<string, true>|null */
    private static ?array $common = null;

    /** @return list<mixed> validation rules for the new password field */
    public static function forNew(?string $email = null): array
    {
        return [
            'required', 'string', 'max:200', 'confirmed',
            Password::min(self::MIN)->letters()->numbers(),
            function (string $attribute, mixed $value, Closure $fail) use ($email) {
                if (is_string($value) && self::isCommon($value, $email)) {
                    $fail('This password is too easy to guess (it is on a list of common passwords). Choose another one.');
                }
            },
        ];
    }

    /** Weak = shorter than the minimum, or common. Used at sign-in for the "please change it" note. */
    public static function isWeak(string $password, ?string $email = null): bool
    {
        return mb_strlen($password) < self::MIN || ! preg_match('/\pL/u', $password) || ! preg_match('/\d/', $password) || self::isCommon($password, $email);
    }

    /**
     * On the list – also with numbers or signs added at the end ("sunshine2026!") – or built from the
     * person's own email name ("abebe12345" for abebe@…), or the product name.
     */
    public static function isCommon(string $password, ?string $email = null): bool
    {
        $p = mb_strtolower(trim($password));
        $base = (string) preg_replace('/[\d\W_]+$/u', '', $p);
        $list = self::list();
        if (isset($list[$p]) || (mb_strlen($base) >= 4 && isset($list[$base]))) {
            return true;
        }
        $name = $email ? mb_strtolower((string) strtok($email, '@')) : '';
        foreach (array_filter([mb_strlen($name) >= 4 ? $name : null, 'hotelms', 'password']) as $word) {
            if ($base === $word || (str_starts_with($base, $word) && mb_strlen($base) <= mb_strlen($word) + 2)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private static function list(): array
    {
        if (self::$common === null) {
            $text = (string) @gzdecode((string) @file_get_contents(resource_path('security/common-passwords.txt.gz')));
            self::$common = array_fill_keys(array_filter(explode("\n", $text)), true);
        }

        return self::$common;
    }
}

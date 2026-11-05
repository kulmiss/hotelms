<?php

namespace App\Support;

/**
 * The hotel permission catalogue (config/permissions.php).
 */
final class Permissions
{
    /** @return array<string, list<string>> group => permission keys */
    public static function groups(): array
    {
        return collect(config('permissions.groups'))
            ->map(fn (array $perms) => array_keys($perms))
            ->all();
    }

    /** @return list<string> */
    public static function all(): array
    {
        return collect(self::groups())->flatten()->values()->all();
    }

    /** @return list<string> permissions a default role gets */
    public static function defaultsFor(string $roleKey): array
    {
        if ($roleKey === 'hotel_admin') {
            return self::all();
        }

        return collect(config('permissions.groups'))
            ->flatMap(fn (array $perms) => $perms)
            ->filter(fn (array $roles) => in_array($roleKey, $roles, true))
            ->keys()
            ->values()
            ->all();
    }

    /** Optional module a permission belongs to, if any. */
    public static function moduleOf(string $permission): ?string
    {
        $modules = config('permissions.modules');

        return $modules[$permission] ?? $modules[strtok($permission, '.')] ?? null;
    }
}

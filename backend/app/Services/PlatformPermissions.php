<?php

namespace App\Services;

use App\Models\PlatformSetting;

/**
 * Which Super Admin team roles have which permission. Defaults come from config/hotelms.php;
 * the Owner can change them on the Team screen. The owner role always has everything.
 * One instance per request (scoped), so the saved map is read once.
 */
class PlatformPermissions
{
    /** @var array<string, list<string>>|null */
    private ?array $map = null;

    /** @return array<string, list<string>> permission => roles */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        // Permission names contain dots, so read the whole map (not config dot-paths).
        $defaults = config('hotelms.platform_permissions', []);
        $saved = PlatformSetting::read('platform_permissions');
        $roles = $this->roles();
        $map = [];
        foreach ($defaults as $permission => $list) {
            $list = is_array($saved) && array_key_exists($permission, $saved) ? (array) $saved[$permission] : $list;
            $map[$permission] = array_values(array_intersect($roles, $list));
        }

        return $this->map = $map;
    }

    /** @return list<string> */
    public function rolesFor(string $permission): array
    {
        return $this->map()[$permission] ?? [];
    }

    /** @return list<string> team roles other than the owner (the owner is locked to everything) */
    public function roles(): array
    {
        return array_values(array_diff(config('hotelms.platform_roles', []), ['owner']));
    }

    /** @param  array<string, list<string>>  $map */
    public function save(array $map): void
    {
        $clean = [];
        foreach (array_keys(config('hotelms.platform_permissions', [])) as $permission) {
            $clean[$permission] = array_values(array_intersect($this->roles(), (array) ($map[$permission] ?? [])));
        }
        PlatformSetting::write('platform_permissions', $clean);
        $this->map = null;
    }
}

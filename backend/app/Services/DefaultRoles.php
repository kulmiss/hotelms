<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Role;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;

/**
 * Gives a hotel the five default roles (permissions.md). Safe to run again:
 * roles that already exist are left as the Hotel Admin edited them.
 */
class DefaultRoles
{
    public function __construct(private TenantContext $tenant) {}

    /** @return array<string, Role> role key => role */
    public function ensureFor(Hotel $hotel): array
    {
        return $this->tenant->runAs($hotel, function () use ($hotel) {
            $roles = [];

            foreach (config('permissions.roles') as $key => $def) {
                $role = Role::where('key', $key)->first();

                if (! $role) {
                    $role = Role::create([
                        'hotel_id' => $hotel->id,
                        'key' => $key,
                        'name' => $def['name'],
                        'is_default' => true,
                        'is_locked' => $def['locked'] ?? false,
                        'max_discount_percent' => $def['max_discount'],
                    ]);

                    if ($key !== 'hotel_admin') {
                        $role->syncPermissions(Permissions::defaultsFor($key));
                    }
                }

                $roles[$key] = $role;
            }

            return $roles;
        });
    }
}

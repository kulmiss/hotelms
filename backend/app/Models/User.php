<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * Users are looked up by email across the whole platform (one login page),
 * so this model is not tenant-scoped. Staff lists must filter by hotel_id.
 */
#[Fillable(['hotel_id', 'role_id', 'name', 'email', 'phone', 'password', 'status', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, LogsActivity, Notifiable, SoftDeletes;

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * The user's role (one per user). The relation already limits to the role's id,
     * so the tenant scope is not needed here.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScope('hotel');
    }

    public function isHotelAdmin(): bool
    {
        return $this->hotel_id !== null && $this->role?->isHotelAdmin() === true
            && $this->role->hotel_id === $this->hotel_id;
    }

    /** @return list<string> hotel permissions of this user */
    public function permissionKeys(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        $role = $this->role;

        return $this->permissionCache = ($this->hotel_id !== null && $role && $role->hotel_id === $this->hotel_id)
            ? $role->permissionKeys()
            : [];
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionKeys(), true);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('role');
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withoutGlobalScope('hotel');
    }

    /** Super Admin team permission check (owner has everything). */
    public function hasPlatformPermission(string $permission): bool
    {
        if (! $this->is_super_admin) {
            return false;
        }

        if ($this->platform_role === 'owner') {
            return true;
        }

        // Permission names contain dots, so read the whole map (not config dot-paths).
        $roles = config('hotelms.platform_permissions', [])[$permission] ?? [];

        return in_array($this->platform_role, $roles, true);
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    /**
     * Branches this user may work in: the Hotel Admin sees every branch,
     * everyone else only their assigned branches (permissions.md).
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(): Collection
    {
        if ($this->hotel_id === null) {
            return collect();
        }

        if ($this->isHotelAdmin()) {
            return Branch::withoutGlobalScope('hotel')->where('hotel_id', $this->hotel_id)->orderBy('name')->get();
        }

        return $this->branches()->where('branches.hotel_id', $this->hotel_id)->orderBy('name')->get();
    }
}

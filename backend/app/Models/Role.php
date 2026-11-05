<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['hotel_id', 'key', 'name', 'description', 'is_default', 'is_locked', 'max_discount_percent'])]
class Role extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_locked' => 'boolean',
            'max_discount_percent' => 'decimal:2',
        ];
    }

    /** @return HasMany<RolePermission, $this> */
    public function permissionRows(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isHotelAdmin(): bool
    {
        return $this->key === 'hotel_admin';
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->isHotelAdmin()
            ? Permissions::all()
            : $this->permissionRows()->pluck('permission')->sort()->values()->all();
    }

    /** @param  list<string>  $permissions */
    public function syncPermissions(array $permissions): void
    {
        $this->permissionRows()->delete();
        $this->permissionRows()->createMany(
            collect($permissions)->unique()->map(fn ($p) => ['permission' => $p])->all()
        );
    }
}

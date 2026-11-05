<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer hotel. Platform-level table: not scoped by tenant.
 */
#[Fillable([
    'name', 'email', 'phone', 'address', 'city', 'logo_path',
    'default_currency', 'timezone', 'status', 'plan_id', 'subscription_ends_at',
])]
class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['trial', 'active', 'grace', 'suspended'];

    protected function casts(): array
    {
        return [
            'subscription_ends_at' => 'date',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * All branches of this hotel (ignores the tenant scope on purpose:
     * the relation itself already limits to this hotel).
     *
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->withoutGlobalScope('hotel');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}

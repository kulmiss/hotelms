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
 *
 * Status: trial → active (paid) → grace (unpaid, 3 days) → suspended (read-only).
 * See App\Services\HotelStatus for the rules.
 */
#[Fillable([
    'name', 'email', 'phone', 'address', 'city', 'logo_path',
    'default_currency', 'timezone', 'status', 'plan_id', 'subscription_ends_at',
    'trial_ends_at', 'trial_plan_id', 'billing_cycle', 'custom_price', 'suspended_at', 'suspend_reason',
])]
class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['trial', 'active', 'grace', 'suspended'];

    protected function casts(): array
    {
        return [
            'subscription_ends_at' => 'date',
            'trial_ends_at' => 'date',
            'custom_price' => 'decimal:2',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function trialPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'trial_plan_id');
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

    /** @return HasMany<SubscriptionPayment, $this> */
    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** The plan whose features apply right now (trial plan during the trial). */
    public function effectivePlan(): ?Plan
    {
        return $this->status === 'trial' && $this->trialPlan ? $this->trialPlan : $this->plan;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\Switches;
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
    'name', 'legal_name', 'tin', 'vat_number', 'email', 'phone', 'address', 'city', 'logo_path',
    'default_currency', 'timezone', 'status', 'plan_id', 'subscription_ends_at',
    'trial_ends_at', 'trial_plan_id', 'billing_cycle', 'custom_price', 'suspended_at', 'suspend_reason', 'is_demo',
    'signup_status', 'signup', 'signup_decided_at', 'signup_decided_by', 'signup_reason',
])]
class Hotel extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUSES = ['trial', 'active', 'grace', 'suspended'];

    /** Registered itself and is not (yet) allowed to work: email not confirmed, waiting for approval, or refused. */
    public function signupLocked(): bool
    {
        return in_array($this->signup_status, ['unverified', 'pending', 'rejected'], true);
    }

    protected function casts(): array
    {
        return [
            'subscription_ends_at' => 'date',
            'trial_ends_at' => 'date',
            'setup_steps' => 'array',
            'setup_completed_at' => 'datetime',
            'custom_price' => 'decimal:2',
            'suspended_at' => 'datetime',
            'is_demo' => 'boolean',
            'signup' => 'array',
            'signup_decided_at' => 'datetime',
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
    /** Room / branch / staff limit: a Super Admin switch (W4) wins over the plan. null = no limit. */
    public function limit(string $what): ?int
    {
        $switch = app(Switches::class)->value($this, "limit.{$what}");

        return $switch !== null ? (int) $switch : $this->effectivePlan()?->{"max_{$what}"};
    }

    public function effectivePlan(): ?Plan
    {
        return $this->status === 'trial' && $this->trialPlan ? $this->trialPlan : $this->plan;
    }
}

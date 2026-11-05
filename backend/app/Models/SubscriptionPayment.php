<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment from a hotel to HotelMS, recorded by the Super Admin team.
 * Platform-level (the Super Admin reads it across hotels), so not tenant-scoped.
 */
#[Fillable([
    'hotel_id', 'plan_id', 'amount', 'currency', 'method', 'reference', 'billing_cycle',
    'months', 'period_start', 'period_end', 'paid_at', 'recorded_by', 'notes',
])]
class SubscriptionPayment extends Model
{
    public const METHODS = ['cash', 'bank_transfer', 'mobile_money', 'other'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

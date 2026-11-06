<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'hotel_id', 'branch_id', 'code', 'guest_id', 'source', 'stay_type', 'check_in_at', 'check_out_at', 'adults', 'children',
    'status', 'expires_at', 'cancellation_policy_id', 'policy_snapshot', 'currency', 'subtotal', 'discount_percent', 'discount_amount', 'tax_total',
    'total', 'deposit_required', 'paid_total', 'cancellation_fee', 'notes', 'created_by', 'checked_in_by', 'checked_out_by', 'pay_later_by', 'pay_later_at', 'cancelled_at', 'cancel_reason', 'cancelled_by',
])]
class Reservation extends Model
{
    use BelongsToHotel, LogsActivity, SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'];

    /** Statuses that hold rooms (count against availability). */
    public const ACTIVE = ['pending', 'confirmed', 'checked_in'];

    public const STAY_TYPES = ['nightly', 'day_use', 'hourly'];

    public const SOURCES = ['walk_in', 'phone', 'email', 'agent', 'other'];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'expires_at' => 'datetime', 'cancelled_at' => 'datetime',
            'policy_snapshot' => 'array', 'adults' => 'integer', 'children' => 'integer',
            'subtotal' => 'decimal:2', 'discount_percent' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
            'deposit_required' => 'decimal:2', 'paid_total' => 'decimal:2', 'cancellation_fee' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    /** @return HasMany<ReservationRoom, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function payLaterBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pay_later_by');
    }

    /** @return BelongsTo<User, $this> */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    public function activityName(): string
    {
        return $this->code;
    }
}

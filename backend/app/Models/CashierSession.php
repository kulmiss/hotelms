<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['hotel_id', 'branch_id', 'user_id', 'opened_at', 'closed_at', 'status', 'approved_by', 'approved_at', 'notes'])]
class CashierSession extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    /** @return HasMany<CashierSessionBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(CashierSessionBalance::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function activityName(): string
    {
        return 'Cashier shift #'.$this->id;
    }
}

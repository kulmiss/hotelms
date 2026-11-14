<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Subscription plan: price per room per month with a monthly minimum
 * (claude/plans.md). Null limits mean unlimited.
 */
#[Fillable([
    'name', 'code', 'description', 'price_per_room', 'minimum_monthly', 'yearly_free_months',
    'is_custom', 'currency', 'max_rooms', 'max_branches', 'max_staff', 'max_storage_mb', 'modules', 'is_active', 'is_public', 'is_recommended', 'sort',
])]
class Plan extends Model
{
    use HasFactory, LogsActivity;

    public const MODULES = ['housekeeping', 'cashier_close', 'shifts', 'attendance'];

    protected function casts(): array
    {
        return [
            'price_per_room' => 'decimal:2',
            'minimum_monthly' => 'decimal:2',
            'yearly_free_months' => 'integer',
            'is_custom' => 'boolean',
            'modules' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_recommended' => 'boolean',
        ];
    }

    /** @return HasMany<Hotel, $this> */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }

    /**
     * Price for one month: rooms × price per room, but never below the minimum.
     */
    public function monthlyPrice(int $rooms): string
    {
        return Money::max(Money::mul($this->price_per_room, (string) $rooms), $this->minimum_monthly);
    }

    /**
     * Price for a billing period. Yearly billing charges 12 − free months per year.
     */
    public function priceFor(int $rooms, string $cycle, int $periods = 1): string
    {
        $chargedMonths = $cycle === 'yearly'
            ? (12 - $this->yearly_free_months) * $periods
            : $periods;

        return Money::mul($this->monthlyPrice($rooms), (string) $chargedMonths);
    }
}

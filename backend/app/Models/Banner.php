<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A message strip shown to all hotels or chosen ones (platform table). */
#[Fillable(['message', 'tone', 'hotel_ids', 'plan_ids', 'starts_at', 'ends_at', 'created_by'])]
class Banner extends Model
{
    protected function casts(): array
    {
        return ['hotel_ids' => 'array', 'plan_ids' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isFor(Hotel $hotel): bool
    {
        $hotels = $this->hotel_ids ?? [];
        $plans = $this->plan_ids ?? [];
        if ($hotels === [] && $plans === []) {
            return true;
        }

        return in_array($hotel->id, array_map('intval', $hotels), true) || in_array((int) $hotel->effectivePlan()?->id, array_map('intval', $plans), true);
    }
}

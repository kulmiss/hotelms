<?php

namespace App\Models\Concerns;

use App\Models\Hotel;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every hotel-owned model: queries only see the current hotel's rows,
 * and new rows get the current hotel automatically.
 */
trait BelongsToHotel
{
    public static function bootBelongsToHotel(): void
    {
        static::addGlobalScope('hotel', function (Builder $query) {
            $tenant = app(TenantContext::class);

            if ($tenant->isBypassed()) {
                return;
            }

            $column = $query->getModel()->qualifyColumn('hotel_id');

            $tenant->hotelId() === null
                ? $query->whereRaw('1 = 0')
                : $query->where($column, $tenant->hotelId());
        });

        static::creating(function ($model) {
            if ($model->hotel_id === null) {
                $model->hotel_id = app(TenantContext::class)->hotelId();
            }
        });
    }

    /** @return BelongsTo<Hotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['hotel_id', 'branch_id', 'name', 'description', 'base_capacity', 'max_capacity', 'nightly_price', 'day_use_price', 'hourly_price', 'currency', 'amenities'])]
class RoomType extends Model
{
    use BelongsToHotel, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'nightly_price' => 'decimal:2',
            'day_use_price' => 'decimal:2',
            'hourly_price' => 'decimal:2',
            'base_capacity' => 'integer',
            'max_capacity' => 'integer',
            'amenities' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}

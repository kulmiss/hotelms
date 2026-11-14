<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A calendar link imported from Airbnb, Booking.com… for one room or a room type (Settings → Calendar sync). */
#[Fillable(['hotel_id', 'branch_id', 'room_id', 'room_type_id', 'name', 'url', 'is_active', 'last_synced_at', 'last_status', 'last_error', 'events_count', 'conflicts'])]
class IcalFeed extends Model
{
    use BelongsToHotel, LogsActivity;

    protected $hidden = ['url'];

    protected function casts(): array
    {
        return ['url' => 'encrypted', 'is_active' => 'boolean', 'last_synced_at' => 'datetime', 'events_count' => 'integer', 'conflicts' => 'array'];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<IcalBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(IcalBlock::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Busy dates read from an imported calendar: they hold the room (or one room of the type) like a booking. */
#[Fillable(['hotel_id', 'ical_feed_id', 'room_id', 'room_type_id', 'uid', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'summary'])]
class IcalBlock extends Model
{
    use BelongsToHotel;

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @return BelongsTo<IcalFeed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(IcalFeed::class, 'ical_feed_id');
    }
}

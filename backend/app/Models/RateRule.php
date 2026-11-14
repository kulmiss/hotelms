<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'room_type_id', 'name', 'rule_type', 'start_date', 'end_date', 'days_of_week', 'guest_type_id', 'min_nights', 'days_before', 'min_stay', 'adjustment', 'value', 'priority', 'is_active'])]
class RateRule extends Model
{
    use BelongsToHotel, LogsActivity;

    /** Rules that set the price of a night (the highest priority wins). */
    public const NIGHT_TYPES = ['weekend', 'season', 'holiday', 'guest_type'];

    /** Rules about the whole stay, added on top of each night's price (one applies: the highest priority). */
    public const STAY_TYPES = ['long_stay', 'early_bird', 'last_minute'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_of_week' => 'array',
            'value' => 'decimal:2',
            'priority' => 'integer',
            'min_nights' => 'integer',
            'days_before' => 'integer',
            'min_stay' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

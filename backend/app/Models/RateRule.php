<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'room_type_id', 'name', 'rule_type', 'start_date', 'end_date', 'days_of_week', 'guest_type_id', 'adjustment', 'value', 'priority', 'is_active'])]
class RateRule extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_of_week' => 'array',
            'value' => 'decimal:2',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

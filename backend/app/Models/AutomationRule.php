<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** "When … if … then …" rule made by the hotel (automation.md → Rule builder). */
#[Fillable(['hotel_id', 'name', 'enabled', 'trigger', 'conditions', 'actions', 'fired_count', 'last_fired_at', 'created_by'])]
class AutomationRule extends Model
{
    use BelongsToHotel;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'conditions' => 'array', 'actions' => 'array', 'last_fired_at' => 'datetime'];
    }
}

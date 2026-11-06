<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The hotel's own on/off and numbers for one ready-made automation. */
#[Fillable(['hotel_id', 'key', 'enabled', 'options'])]
class AutomationSetting extends Model
{
    use BelongsToHotel;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'options' => 'array'];
    }
}

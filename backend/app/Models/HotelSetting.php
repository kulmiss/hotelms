<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'key', 'value'])]
class HotelSetting extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}

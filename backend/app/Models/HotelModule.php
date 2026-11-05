<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'module', 'is_enabled'])]
class HotelModule extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}

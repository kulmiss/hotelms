<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'name', 'rate', 'applies_to', 'is_inclusive', 'is_active'])]
class Tax extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'is_inclusive' => 'boolean', 'is_active' => 'boolean'];
    }
}

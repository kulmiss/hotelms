<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'name', 'default_price', 'currency', 'is_active'])]
class ChargeType extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['default_price' => 'decimal:2', 'is_active' => 'boolean'];
    }
}

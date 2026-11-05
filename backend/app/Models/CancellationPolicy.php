<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'name', 'free_until_hours', 'penalty_type', 'penalty_value', 'applies_to_no_show', 'is_default'])]
class CancellationPolicy extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['free_until_hours' => 'integer', 'penalty_value' => 'decimal:2', 'applies_to_no_show' => 'boolean', 'is_default' => 'boolean'];
    }
}

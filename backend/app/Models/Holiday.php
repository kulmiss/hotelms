<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A day of the hotel's holiday list (Settings → Prices → Holidays); "holiday" rate rules apply on these nights. */
#[Fillable(['hotel_id', 'name', 'date', 'kind', 'is_active'])]
class Holiday extends Model
{
    use BelongsToHotel, LogsActivity;

    public const KINDS = ['fixed', 'calculated', 'expected', 'own'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_active' => 'boolean'];
    }
}

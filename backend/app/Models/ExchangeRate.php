<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['hotel_id', 'from_currency', 'to_currency', 'rate', 'effective_date', 'entered_by'])]
class ExchangeRate extends Model
{
    use BelongsToHotel, LogsActivity;

    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'effective_date' => 'date'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Price of one night (or one day-use / hourly block), as charged at booking time. Scoped through its reservation room. */
#[Fillable(['reservation_room_id', 'date', 'label', 'amount', 'rate_rule_id', 'rule_name'])]
class ReservationRoomRate extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }
}

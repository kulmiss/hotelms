<?php

namespace App\Events;

use App\Models\Reservation;
use Illuminate\Foundation\Events\Dispatchable;

/** A stay ended; its rooms are now dirty (housekeeping makes cleaning tasks from this). */
class GuestCheckedOut
{
    use Dispatchable;

    public function __construct(public Reservation $reservation) {}
}

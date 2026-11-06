<?php

namespace App\Listeners;

use App\Events\GuestCheckedOut;
use App\Services\Modules\Housekeeping;

class CreateCleaningTasks
{
    public function __construct(private Housekeeping $housekeeping) {}

    public function handle(GuestCheckedOut $event): void
    {
        $this->housekeeping->tasksForCheckOut($event->reservation);
    }
}

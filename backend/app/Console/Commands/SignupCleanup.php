<?php

namespace App\Console\Commands;

use App\Services\Signup\SignupCleanup as Cleanup;
use Illuminate\Console\Command;

class SignupCleanup extends Command
{
    protected $signature = 'hotelms:signup-cleanup';

    protected $description = 'Remove sign-ups not confirmed in 7 days; flag Free hotels unused for 90 days (nothing else is deleted)';

    public function handle(Cleanup $cleanup): int
    {
        $r = $cleanup->run();
        $this->info("{$r['removed']} unconfirmed sign-up(s) removed, {$r['kept']} kept (they have guest data), {$r['flagged']} hotel(s) flagged inactive, {$r['unflagged']} active again.");

        return self::SUCCESS;
    }
}

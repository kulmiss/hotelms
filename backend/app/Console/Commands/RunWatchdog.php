<?php

namespace App\Console\Commands;

use App\Services\Watchdog\Watchdog;
use Illuminate\Console\Command;

/** Super Admin watchdog: quick checks every 10 minutes, with --data every hour (see routes/console.php). */
class RunWatchdog extends Command
{
    protected $signature = 'watchdog:run {--data : also the checks that read whole tables (mixed hotel data, money, rooms, fraud)}';

    protected $description = 'Look for security problems, bugs and data leaks, and warn the HotelMS team';

    public function handle(Watchdog $watchdog): int
    {
        $open = $watchdog->run((bool) $this->option('data'));
        $this->info($open->count().' open findings.');

        return self::SUCCESS;
    }
}

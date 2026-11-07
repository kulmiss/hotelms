<?php

namespace App\Console\Commands;

use App\Services\DemoHotel;
use Illuminate\Console\Command;

class ResetDemoHotel extends Command
{
    protected $signature = 'demo:reset {--rooms=60 : more rooms to test speed with a big hotel}';

    protected $description = 'Rebuild the demo hotel with fresh sample data (runs every night)';

    public function handle(DemoHotel $demo): int
    {
        $hotel = $demo->reset(max(20, (int) $this->option('rooms')));
        $this->info("Demo hotel ready: {$hotel->name} (password for every demo user: ".DemoHotel::PASSWORD.')');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\DemoHotel;
use Illuminate\Console\Command;

class ResetDemoHotel extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Rebuild the demo hotel with fresh sample data (runs every night)';

    public function handle(DemoHotel $demo): int
    {
        $hotel = $demo->reset();
        $this->info("Demo hotel ready: {$hotel->name} (password for every demo user: ".DemoHotel::PASSWORD.')');

        return self::SUCCESS;
    }
}

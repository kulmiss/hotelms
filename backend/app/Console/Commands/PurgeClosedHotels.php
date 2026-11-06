<?php

namespace App\Console\Commands;

use App\Services\HotelClosing;
use Illuminate\Console\Command;

class PurgeClosedHotels extends Command
{
    protected $signature = 'hotelms:purge-closed-hotels';

    protected $description = 'Delete for good the data of hotels closed more than 90 days ago';

    public function handle(HotelClosing $closing): int
    {
        $this->info($closing->purgeExpired().' closed hotel(s) deleted.');

        return self::SUCCESS;
    }
}

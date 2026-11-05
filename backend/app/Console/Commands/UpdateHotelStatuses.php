<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\HotelStatus;
use Illuminate\Console\Command;

/**
 * Moves hotels between trial / active / grace / suspended as dates pass.
 * Runs every hour from the scheduler (cron on shared hosting).
 */
class UpdateHotelStatuses extends Command
{
    protected $signature = 'hotels:update-statuses';

    protected $description = 'Update hotel subscription statuses (trial, active, grace, suspended)';

    public function handle(HotelStatus $status): int
    {
        $changed = 0;

        Hotel::query()->chunkById(200, function ($hotels) use ($status, &$changed) {
            foreach ($hotels as $hotel) {
                $changed += $status->refresh($hotel) ? 1 : 0;
            }
        });

        $this->info("Hotel statuses updated: {$changed} changed.");

        return self::SUCCESS;
    }
}

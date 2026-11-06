<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Booking\ReservationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Cancels pending bookings whose deposit time has run out (workflows.md Q2). Runs every minute from cron. */
class ExpirePendingReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Cancel pending bookings that were not confirmed in time';

    public function handle(TenantContext $tenant): int
    {
        $total = 0;
        Hotel::query()->whereIn('id', fn ($q) => $q->select('hotel_id')->from('reservations')->where('status', 'pending')->where('expires_at', '<=', now()))
            ->each(function (Hotel $hotel) use ($tenant, &$total) {
                try {
                    $total += $tenant->runAs($hotel, fn () => app(ReservationService::class)->expireDue());
                } catch (\Throwable $e) {
                    report($e); // one hotel's problem never stops the others
                }
            });
        $this->info("Expired {$total} booking(s).");

        return self::SUCCESS;
    }
}

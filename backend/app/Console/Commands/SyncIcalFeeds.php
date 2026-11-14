<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\IcalFeed;
use App\Services\Channels\CalendarSync;
use App\Services\Channels\IcalSync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Calendar sync (owner, 14 Nov 2026): every 15 minutes from cron, each active imported link that was not read in
 * the last 15 minutes is read again. A hotel whose plan has no calendar sync is skipped (nothing is deleted).
 */
class SyncIcalFeeds extends Command
{
    protected $signature = 'ical:sync {--max=200 : at most this many links per run}';

    protected $description = 'Read the imported calendar links (Airbnb, Booking.com…) again';

    public function handle(TenantContext $tenant, IcalSync $sync, CalendarSync $limits): int
    {
        $left = (int) $this->option('max');
        $done = 0;
        $due = now()->subMinutes(IcalSync::EVERY_MINUTES - 1);
        Hotel::query()->whereIn('status', ['trial', 'active', 'grace'])
            ->whereIn('id', fn ($q) => $q->select('hotel_id')->from('ical_feeds')->where('is_active', true))
            ->each(function (Hotel $hotel) use ($tenant, $sync, $limits, $due, &$left, &$done) {
                if ($left <= 0 || $limits->limit($hotel) === 0) {
                    return;
                }
                try {
                    $tenant->runAs($hotel, function () use ($sync, $due, &$left, &$done) {
                        IcalFeed::where('is_active', true)->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $due))
                            ->orderBy('last_synced_at')->limit($left)->get()
                            ->each(function (IcalFeed $feed) use ($sync, &$left, &$done) {
                                $sync->sync($feed);
                                $left--;
                                $done++;
                            });
                    });
                } catch (Throwable $e) {
                    report($e); // one hotel's problem never stops the others
                }
            });
        $this->info("Synced {$done} calendar link(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Automation\AutomationSchedule;
use App\Services\Automation\PlatformAutomations;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Every 5 minutes from cron: time-based automations for every hotel, then the HotelMS team ones. */
class RunAutomations extends Command
{
    protected $signature = 'automations:run';

    protected $description = 'Run the time-based automations (reminders, late things, summaries, price suggestions)';

    public function handle(TenantContext $tenant, AutomationSchedule $schedule, PlatformAutomations $platform): int
    {
        $sent = 0;
        Hotel::query()->where('is_demo', false)->whereIn('status', ['trial', 'active', 'grace'])->each(function (Hotel $hotel) use ($tenant, $schedule, &$sent) {
            try {
                $sent += $tenant->runAs($hotel, fn () => $schedule->run($hotel));
            } catch (Throwable $e) {
                report($e); // one hotel's problem never stops the others
            }
        });
        $sent += $platform->run();
        // Firing memory older than 60 days is no longer needed.
        DB::table('automation_firings')->where('fired_at', '<', now()->subDays(60))->delete();
        DB::table('app_notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(90))->delete();
        $this->info("Messages sent: {$sent}");

        return self::SUCCESS;
    }
}

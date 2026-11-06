<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Notify\Telegram;
use App\Services\Reports\Automation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every minute from cron: link new Telegram chats and send scheduled reports that are due.
 * With --alerts (every 15 minutes): check the smart alert rules.
 */
class RunReportAutomation extends Command
{
    protected $signature = 'reports:automation {--alerts : Check smart alerts instead of sending reports}';

    protected $description = 'Send scheduled reports (and check smart alerts with --alerts)';

    public function handle(TenantContext $tenant, Automation $automation, Telegram $telegram): int
    {
        if (! $this->option('alerts')) {
            $telegram->poll();
        }
        $done = 0;
        Hotel::query()->where('is_demo', false)->whereIn('status', ['trial', 'active', 'grace'])->each(function (Hotel $hotel) use ($tenant, $automation, &$done) {
            try {
                $done += $tenant->runAs($hotel, function () use ($hotel, $automation) {
                    if ($this->option('alerts')) {
                        $automation->ensureDefaults($hotel);

                        return $automation->checkAlerts($hotel);
                    }

                    return $automation->runDue($hotel);
                });
            } catch (Throwable $e) {
                report($e); // one hotel's problem must not stop the others
            }
        });
        $this->info(($this->option('alerts') ? 'New alerts: ' : 'Reports sent: ').$done);

        return self::SUCCESS;
    }
}

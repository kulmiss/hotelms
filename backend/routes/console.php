<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs. On shared hosting, one cron line runs them every minute:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
*/

Schedule::command('hotels:update-statuses')->hourly()->withoutOverlapping();

// The demo hotel starts fresh every night.
Schedule::command('demo:reset')->dailyAt('02:30')->withoutOverlapping();

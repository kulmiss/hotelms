<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs. On shared hosting, one cron line runs them every minute:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
*/

Schedule::command('hotels:update-statuses')->hourly()->withoutOverlapping();

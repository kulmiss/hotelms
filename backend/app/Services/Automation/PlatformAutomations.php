<?php

namespace App\Services\Automation;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Subscription and customer-care automations for the HotelMS team (automation.md 34–38). */
class PlatformAutomations
{
    public function __construct(private Automations $a) {}

    public function run(): int
    {
        $sent = 0;
        foreach (['subscriptionReminder', 'overdueNotice', 'trialEndingSales', 'inactiveHotel', 'stuckInSetup'] as $check) {
            try {
                $sent += $this->{$check}();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> HotelMS team members with a platform permission */
    private function team(string $permission): Collection
    {
        return User::where('is_super_admin', true)->where('status', 'active')->get()->filter(fn (User $u) => $u->hasPlatformPermission($permission))->values();
    }

    private function hotels()
    {
        return Hotel::query()->where('is_demo', false);
    }

    // 34. Subscription ends in 7 days / 1 day → tell the Hotel Admin.
    private function subscriptionReminder(): int
    {
        $n = 0;
        foreach ([7, 1] as $days) {
            $date = now('Africa/Addis_Ababa')->addDays($days)->toDateString();
            $this->hotels()->where('status', 'active')->whereDate('subscription_ends_at', $date)->get()->each(function (Hotel $h) use ($days, $date, &$n) {
                if ($this->a->enabled($h, 'subscription_reminder') && $this->a->once($h, 'subscription_reminder', "{$date}:{$days}")) {
                    $n += $this->a->tell($h, 'subscription_reminder', $this->a->people($h, ['hotel_admin']), $days === 1 ? 'Your subscription ends tomorrow' : "Your subscription ends in {$days} days", 'Pay to keep using the system without a break.', '/settings/subscription');
                }
            });
        }

        return $n;
    }

    // 35. Payment overdue → grace notice (read-only follows from the status update).
    private function overdueNotice(): int
    {
        $n = 0;
        $this->hotels()->where('status', 'grace')->get()->each(function (Hotel $h) use (&$n) {
            if ($this->a->enabled($h, 'overdue_notice') && $this->a->once($h, 'overdue_notice', (string) $h->subscription_ends_at?->toDateString())) {
                $n += $this->a->tell($h, 'overdue_notice', $this->a->people($h, ['hotel_admin']), 'Your payment is overdue', 'Pay within the grace days or the system becomes read-only.', '/settings/subscription', true);
            }
        });

        return $n;
    }

    // 36. Trial ends in 3 days → the sales team.
    private function trialEndingSales(): int
    {
        $date = now('Africa/Addis_Ababa')->addDays(3)->toDateString();
        $n = 0;
        $this->hotels()->where('status', 'trial')->whereDate('trial_ends_at', $date)->get()->each(function (Hotel $h) use ($date, &$n) {
            if ($this->a->enabled($h, 'trial_ending_sales') && $this->a->once($h, 'trial_ending_sales', $date)) {
                $n += $this->a->tell(null, 'trial_ending_sales', $this->team('hotels.create'), "{$h->name}: trial ends in 3 days", 'A good moment to call them.', "/admin/hotels/{$h->id}");
            }
        });

        return $n;
    }

    // 37. Hotel inactive for days → "at risk".
    private function inactiveHotel(): int
    {
        $days = 7;
        $since = now()->subDays($days);
        $week = now()->format('o-W');
        $n = 0;
        $this->hotels()->whereIn('status', ['trial', 'active'])->where('created_at', '<=', $since)->get()->each(function (Hotel $h) use ($since, $week, $days, &$n) {
            $days = (int) ($this->a->option($h, 'inactive_hotel', 'days') ?: $days);
            $active = DB::table('activity_logs')->where('hotel_id', $h->id)->where('created_at', '>=', now()->subDays($days))->exists();
            if (! $active && $this->a->enabled($h, 'inactive_hotel') && $this->a->once($h, 'inactive_hotel', $week)) {
                $n += $this->a->tell(null, 'inactive_hotel', $this->team('support.answer'), "{$h->name} has not used the system for {$days} days", 'At risk of leaving. Call them to help.', "/admin/hotels/{$h->id}");
            }
        });

        return $n;
    }

    // 38. Stuck in setup → support offers help.
    private function stuckInSetup(): int
    {
        $n = 0;
        $this->hotels()->whereIn('status', ['trial', 'active'])->whereNull('setup_completed_at')->where('created_at', '<=', now()->subDays(5))->get()->each(function (Hotel $h) use (&$n) {
            if ($this->a->enabled($h, 'stuck_in_setup') && $this->a->once($h, 'stuck_in_setup', 'setup')) {
                $n += $this->a->tell(null, 'stuck_in_setup', $this->team('support.answer'), "{$h->name} has not finished setup", 'Created '.Carbon::parse($h->created_at)->diffForHumans().'. Offer help.', "/admin/hotels/{$h->id}");
            }
        });

        return $n;
    }
}

<?php

namespace App\Services\Automation;

use App\Models\Approval;
use App\Models\Hotel;
use App\Models\HousekeepingTask;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Reports\Insights;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Automations that look at the clock (every 5 minutes from cron): reminders, things that are late,
 * daily and weekly summaries, price suggestions. Each check runs on its own: one problem never stops the others.
 */
class AutomationSchedule
{
    private Hotel $hotel;

    private Carbon $now; // hotel time

    public function __construct(private Automations $a, private RuleEngine $rules, private AutomationEvents $events) {}

    /** Runs every check for one hotel (inside its tenant context). Returns how many messages went out. */
    public function run(Hotel $hotel): int
    {
        $this->hotel = $hotel;
        $this->now = HotelClock::now($hotel);
        $sent = 0;
        foreach ([
            'deposit_reminder', 'arrival_reminder', 'no_show_suggestion', 'shift_start_brief', 'checkout_balance_reminder', 'outstanding_follow_up',
            'close_cashier_reminder', 'exchange_rate_reminder', 'arrival_room_not_ready', 'long_maintenance', 'stayover_not_cleaned',
            'late_for_shift', 'understaffed_shift', 'price_up_suggestion', 'price_down_suggestion', 'target_risk', 'daily_report', 'weekly_summary',
            'guest_details_missing',
        ] as $key) {
            if (! $this->a->enabled($hotel, $key)) {
                continue;
            }
            try {
                $sent += (int) $this->{str_replace('_', '', lcfirst(ucwords($key, '_')))}();
            } catch (Throwable $e) {
                report($e);
            }
        }
        try {
            $sent += $this->rules->runDaily($hotel);
        } catch (Throwable $e) {
            report($e);
        }

        return $sent;
    }

    private function utc(Carbon $local): Carbon
    {
        return $local->copy()->utc();
    }

    private function today(): Carbon
    {
        return $this->now->copy()->startOfDay();
    }

    private function after(string $time): bool
    {
        return $this->now->format('H:i') >= $time;
    }

    private function tell(string $key, $users, string $title, ?string $body = null, ?string $link = null): int
    {
        return $this->a->tell($this->hotel, $key, $users, $title, $body, $link);
    }

    // 2. Pending booking about to expire → collect the deposit.
    private function depositReminder(): int
    {
        $hours = (int) $this->a->option($this->hotel, 'deposit_reminder', 'hours');
        $n = 0;
        Reservation::where('status', 'pending')->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addHours($hours)])->with('guest')->get()
            ->each(function (Reservation $r) use (&$n) {
                if ($this->a->once($this->hotel, 'deposit_reminder', "r{$r->id}")) {
                    $n += $this->tell('deposit_reminder', $this->a->recipients($this->hotel, 'deposit_reminder', $r->branch_id), "Collect the deposit for {$r->code}", ($r->guest?->full_name ?? '').' · expires '.HotelClock::local($r->expires_at, $this->hotel)->format('H:i'), "/reservations/{$r->id}");
                }
            });

        return $n;
    }

    // 4. Day before arrival → remind the guest (email).
    private function arrivalReminder(): int
    {
        if (! $this->after('10:00')) {
            return 0;
        }
        $from = $this->utc($this->today()->addDay());
        $n = 0;
        Reservation::whereIn('status', ['pending', 'confirmed'])->whereBetween('check_in_at', [$from, $from->copy()->addDay()])->with(['guest', 'branch'])->get()
            ->each(function (Reservation $r) use (&$n) {
                if ($r->guest?->email && $this->a->once($this->hotel, 'arrival_reminder', "r{$r->id}")) {
                    $this->events->mail($r->guest->email, $this->hotel, "See you tomorrow: {$r->code}", [
                        "Dear {$r->guest->full_name}, we look forward to welcoming you tomorrow.",
                        'Check-in from '.HotelClock::local($r->check_in_at, $this->hotel)->format('H:i').' at '.($r->branch?->name ?? $this->hotel->name).'.',
                        $r->branch?->address ?? $this->hotel->address ?? '',
                        $this->hotel->phone ? "Questions? Call us on {$this->hotel->phone}." : '',
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    // 5. Arrival time long passed → suggest a no-show.
    private function noShowSuggestion(): int
    {
        $hours = (int) $this->a->option($this->hotel, 'no_show_suggestion', 'hours');
        $n = 0;
        Reservation::where('status', 'confirmed')->where('check_in_at', '<=', now()->subHours($hours))->where('check_in_at', '>=', now()->subDays(2))->with('guest')->get()
            ->each(function (Reservation $r) use (&$n) {
                if ($this->a->once($this->hotel, 'no_show_suggestion', "r{$r->id}")) {
                    $n += $this->tell('no_show_suggestion', $this->a->recipients($this->hotel, 'no_show_suggestion', $r->branch_id), "{$r->code} has not arrived", ($r->guest?->full_name ?? '').' · mark a no-show if they are not coming.', "/reservations/{$r->id}");
                }
            });

        return $n;
    }

    // 11. Start of a shift → the brief for that shift.
    private function shiftStartBrief(): int
    {
        $n = 0;
        $date = $this->now->toDateString();
        DB::table('shift_assignments as a')->join('shifts as s', 's.id', '=', 'a.shift_id')->where('a.hotel_id', $this->hotel->id)->where('a.date', $date)
            ->where('s.start_time', '<=', $this->now->format('H:i:s'))->where('s.start_time', '>=', $this->now->copy()->subMinutes(30)->format('H:i:s'))
            ->get(['a.id', 'a.user_id', 'a.branch_id', 's.name'])->each(function ($a) use (&$n) {
                if (! $this->a->once($this->hotel, 'shift_start_brief', "a{$a->id}")) {
                    return;
                }
                $start = $this->utc($this->today());
                $arrivals = Reservation::where('branch_id', $a->branch_id)->whereIn('status', ['pending', 'confirmed'])->whereBetween('check_in_at', [$start, $start->copy()->addDay()])->count();
                $departures = Reservation::where('branch_id', $a->branch_id)->where('status', 'checked_in')->whereBetween('check_out_at', [$start, $start->copy()->addDay()])->count();
                $unpaid = Invoice::where('branch_id', $a->branch_id)->whereIn('status', ['open', 'partially_paid'])->where('balance', '>', 0)->count();
                $n += $this->tell('shift_start_brief', User::whereKey($a->user_id)->get(), "{$a->name} shift: {$arrivals} arriving, {$departures} leaving", "{$unpaid} bills with money owed.", '/rooms');
            });

        return $n;
    }

    // 12. Day before check-out with money owed → remind reception.
    private function checkoutBalanceReminder(): int
    {
        if (! $this->after('09:00')) {
            return 0;
        }
        $from = $this->utc($this->today()->addDay());
        $n = 0;
        Reservation::where('status', 'checked_in')->whereBetween('check_out_at', [$from, $from->copy()->addDay()])->whereColumn('paid_total', '<', 'total')->with('guest')->get()
            ->each(function (Reservation $r) use (&$n) {
                if ($this->a->once($this->hotel, 'checkout_balance_reminder', "r{$r->id}")) {
                    $owed = Money::round(bcsub((string) $r->total, (string) $r->paid_total, 2));
                    $n += $this->tell('checkout_balance_reminder', $this->a->recipients($this->hotel, 'checkout_balance_reminder', $r->branch_id), ($r->guest?->full_name ?? $r->code)." leaves tomorrow and owes {$r->currency} {$owed}", $r->code, "/reservations/{$r->id}");
                }
            });

        return $n;
    }

    // 13. Left with money owed (approved) → follow up after N days.
    private function outstandingFollowUp(): int
    {
        $days = (int) $this->a->option($this->hotel, 'outstanding_follow_up', 'days');
        $n = 0;
        Invoice::where('status', 'outstanding')->where('balance', '>', 0)->whereNotNull('outstanding_approved_at')->where('outstanding_approved_at', '<=', now()->subDays($days))->with('guest')->get()
            ->each(function (Invoice $i) use (&$n) {
                if ($this->a->once($this->hotel, 'outstanding_follow_up', "i{$i->id}")) {
                    $n += $this->tell('outstanding_follow_up', $this->a->recipients($this->hotel, 'outstanding_follow_up', $i->branch_id), "Follow up: {$i->number} still owes {$i->currency} {$i->balance}", $i->guest?->full_name.($i->guest?->phone ? " · {$i->guest->phone}" : ''), "/billing/{$i->id}");
                }
            });

        return $n;
    }

    // 14. End of a shift with the cashier shift still open → close it.
    private function closeCashierReminder(): int
    {
        $n = 0;
        $date = $this->now->toDateString();
        DB::table('shift_assignments as a')->join('shifts as s', 's.id', '=', 'a.shift_id')->where('a.hotel_id', $this->hotel->id)->where('a.date', $date)
            ->where('s.end_time', '<=', $this->now->format('H:i:s'))->where('s.end_time', '>=', $this->now->copy()->subMinutes(30)->format('H:i:s'))
            ->get(['a.id', 'a.user_id'])->each(function ($a) use (&$n) {
                $open = DB::table('cashier_sessions')->where('hotel_id', $this->hotel->id)->where('user_id', $a->user_id)->where('status', 'open')->exists();
                if ($open && $this->a->once($this->hotel, 'close_cashier_reminder', "a{$a->id}")) {
                    $n += $this->tell('close_cashier_reminder', User::whereKey($a->user_id)->get(), 'Your shift has ended: close your cashier shift', 'Count the cash and close it before you leave.', '/cashier');
                }
            });

        return $n;
    }

    // 16. Exchange rate not updated today.
    private function exchangeRateReminder(): int
    {
        if (! $this->after((string) $this->a->option($this->hotel, 'exchange_rate_reminder', 'time'))) {
            return 0;
        }
        $currencies = DB::table('hotel_currencies')->where('hotel_id', $this->hotel->id)->count();
        $today = $this->now->toDateString();
        if ($currencies < 2 || DB::table('exchange_rates')->where('hotel_id', $this->hotel->id)->where('effective_date', $today)->exists() || ! $this->a->once($this->hotel, 'exchange_rate_reminder', $today)) {
            return 0;
        }

        return $this->tell('exchange_rate_reminder', $this->a->recipients($this->hotel, 'exchange_rate_reminder'), "Enter today's exchange rates", 'Payments in other currencies use the latest rate.', '/settings/currencies');
    }

    // 22. Arrival soon and the room is not ready.
    private function arrivalRoomNotReady(): int
    {
        $hours = (int) $this->a->option($this->hotel, 'arrival_room_not_ready', 'hours');
        $n = 0;
        Reservation::whereIn('status', ['pending', 'confirmed'])->whereBetween('check_in_at', [now(), now()->addHours($hours)])->with(['rooms.room', 'guest'])->get()
            ->each(function (Reservation $r) use (&$n) {
                foreach ($r->rooms as $rr) {
                    if ($rr->room && ! in_array($rr->room->status, ['available', 'clean'], true) && $this->a->once($this->hotel, 'arrival_room_not_ready', "rr{$rr->id}")) {
                        $n += $this->tell('arrival_room_not_ready', $this->a->recipients($this->hotel, 'arrival_room_not_ready', $r->branch_id), "Room {$rr->room->number} is not ready", ($r->guest?->full_name ?? $r->code).' arrives at '.HotelClock::local($r->check_in_at, $this->hotel)->format('H:i'), '/housekeeping');
                    }
                }
            });

        return $n;
    }

    // Quick check-in: hours after check-in the guest's ID or nationality is still missing → remind who checked them in.
    private function guestDetailsMissing(): int
    {
        $hours = (int) $this->a->option($this->hotel, 'guest_details_missing', 'hours');
        $n = 0;
        Reservation::where('status', 'checked_in')->whereHas('guest', fn ($q) => $q->detailsMissing())
            ->whereHas('rooms', fn ($q) => $q->where('actual_check_in_at', '<=', now()->subHours($hours)))
            ->with(['guest', 'checkedInBy'])->get()
            ->each(function (Reservation $r) use (&$n) {
                if (! $this->a->once($this->hotel, 'guest_details_missing', "g{$r->guest_id}r{$r->id}")) {
                    return;
                }
                $who = $r->checkedInBy && $r->checkedInBy->status === 'active' ? collect([$r->checkedInBy]) : $this->a->recipients($this->hotel, 'guest_details_missing', $r->branch_id);
                $n += $this->tell('guest_details_missing', $who, 'Finish the guest details for '.($r->guest?->full_name ?? $r->code), 'ID number and nationality are still missing.', "/guests/{$r->guest_id}");
            });

        return $n;
    }

    // 23. Room in maintenance too long.
    private function longMaintenance(): int
    {
        $days = (int) $this->a->option($this->hotel, 'long_maintenance', 'days');
        $n = 0;
        Room::whereIn('status', ['maintenance', 'out_of_order'])->where('updated_at', '<=', now()->subDays($days))->get()
            ->each(function (Room $room) use (&$n) {
                if ($this->a->once($this->hotel, 'long_maintenance', "room{$room->id}:".$room->updated_at?->format('Ymd'))) {
                    $n += $this->tell('long_maintenance', $this->a->recipients($this->hotel, 'long_maintenance', $room->branch_id), "Room {$room->number} has been out of use for days", 'Since '.HotelClock::local($room->updated_at, $this->hotel)->format('d M'), '/rooms?branch='.$room->branch_id.'&room='.$room->id);
                }
            });

        return $n;
    }

    // 25. Stay-over room not cleaned by the afternoon.
    private function stayoverNotCleaned(): int
    {
        if (! $this->after((string) $this->a->option($this->hotel, 'stayover_not_cleaned', 'time'))) {
            return 0;
        }
        $start = $this->utc($this->today());
        $tomorrow = $start->copy()->addDay();
        $rooms = DB::table('reservation_rooms as rr')->join('rooms', 'rooms.id', '=', 'rr.room_id')->where('rr.hotel_id', $this->hotel->id)
            ->where('rr.status', 'checked_in')->where('rr.check_out_at', '>=', $tomorrow)->where('rr.actual_check_in_at', '<', $start)
            ->get(['rooms.id', 'rooms.number', 'rooms.branch_id']);
        $missing = $rooms->filter(fn ($r) => ! HousekeepingTask::where('room_id', $r->id)->where('status', 'done')->where('completed_at', '>=', $start)->exists());
        if ($missing->isEmpty() || ! $this->a->once($this->hotel, 'stayover_not_cleaned', $this->now->toDateString())) {
            return 0;
        }

        return $this->tell('stayover_not_cleaned', $this->a->recipients($this->hotel, 'stayover_not_cleaned'), $missing->count().' stay-over rooms not cleaned yet', 'Rooms '.$missing->pluck('number')->implode(', '), '/housekeeping');
    }

    // 26. Staff member late for a shift.
    private function lateForShift(): int
    {
        $minutes = (int) $this->a->option($this->hotel, 'late_for_shift', 'minutes');
        $n = 0;
        $date = $this->now->toDateString();
        DB::table('shift_assignments as a')->join('shifts as s', 's.id', '=', 'a.shift_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.hotel_id', $this->hotel->id)->where('a.date', $date)->where('a.status', 'scheduled')
            ->where('s.start_time', '<=', $this->now->copy()->subMinutes($minutes)->format('H:i:s'))->where('s.end_time', '>', $this->now->format('H:i:s'))
            ->get(['a.id', 'a.user_id', 'a.branch_id', 's.name', 's.start_time', 'u.name as person'])->each(function ($a) use (&$n, $date) {
                $in = DB::table('attendances')->where('hotel_id', $this->hotel->id)->where('user_id', $a->user_id)->where('date', $date)->whereNotNull('clock_in_at')->exists();
                if (! $in && $this->a->once($this->hotel, 'late_for_shift', "a{$a->id}")) {
                    $n += $this->tell('late_for_shift', $this->a->recipients($this->hotel, 'late_for_shift', $a->branch_id), "{$a->person} has not clocked in", "{$a->name} shift started at ".substr($a->start_time, 0, 5), '/attendance');
                }
            });

        return $n;
    }

    // 27. Tomorrow's shift has nobody on it.
    private function understaffedShift(): int
    {
        if (! $this->after('09:00')) {
            return 0;
        }
        $tomorrow = $this->today()->addDay()->toDateString();
        $empty = DB::table('shifts as s')->where('s.hotel_id', $this->hotel->id)
            ->whereNotExists(fn ($q) => $q->from('shift_assignments as a')->whereColumn('a.shift_id', 's.id')->where('a.date', $tomorrow))
            ->get(['s.id', 's.name', 's.branch_id']);
        $n = 0;
        foreach ($empty as $s) {
            if ($this->a->once($this->hotel, 'understaffed_shift', "s{$s->id}:{$tomorrow}")) {
                $n += $this->tell('understaffed_shift', $this->a->recipients($this->hotel, 'understaffed_shift', $s->branch_id), "Nobody is on tomorrow's {$s->name} shift", 'Add staff on the shift roster.', '/shifts');
            }
        }

        return $n;
    }

    // 29 / 30. Price suggestions (the manager approves each one).
    private function priceUpSuggestion(): int
    {
        return $this->priceSuggestions('up');
    }

    private function priceDownSuggestion(): int
    {
        return $this->priceSuggestions('down');
    }

    private function priceSuggestions(string $way): int
    {
        if (! $this->after('08:00')) {
            return 0;
        }
        $key = $way === 'up' ? 'price_up_suggestion' : 'price_down_suggestion';
        $limit = (float) $this->a->option($this->hotel, $key, 'occupancy');
        $percent = (int) $this->a->option($this->hotel, $key, 'percent');
        $n = 0;
        foreach (DB::table('branches')->where('hotel_id', $this->hotel->id)->whereNull('deleted_at')->get(['id', 'name']) as $branch) {
            $days = Insights::for($this->hotel, [$branch->id])->forecast(14)['days'];
            foreach ($days as $i => $d) {
                $hit = $way === 'up'
                    ? $d['occupancy'] >= $limit
                    : $i >= 2 && $d['occupancy'] <= $limit && $d['usual_occupancy'] - $d['occupancy'] >= 15;
                if (! $hit || $d['season']) {
                    continue; // a price rule already covers this date
                }
                $sign = $way === 'up' ? '+' : '-';
                $approval = Approval::firstOrCreate(['fingerprint' => "price:{$branch->id}:{$d['date']}"], [
                    'branch_id' => $branch->id, 'kind' => "price_{$way}", 'status' => 'pending',
                    'title' => "{$sign}{$percent}% on ".Carbon::parse($d['date'])->format('D d M')." at {$branch->name} (".round($d['occupancy']).'% booked)',
                    'payload' => ['date' => $d['date'], 'percent' => $way === 'up' ? $percent : -$percent, 'occupancy' => $d['occupancy'], 'usual' => $d['usual_occupancy']],
                ]);
                if ($approval->wasRecentlyCreated) {
                    $n += $this->tell($key, $this->a->recipients($this->hotel, $key, $branch->id), 'Price suggestion waits for your approval', $approval->title, '/approvals');
                }
            }
        }

        return $n;
    }

    // 31. Month heading below target.
    private function targetRisk(): int
    {
        if ($this->now->day < 10) {
            return 0; // too early to tell
        }
        $percent = (float) $this->a->option($this->hotel, 'target_risk', 'percent');
        $branches = DB::table('branches')->where('hotel_id', $this->hotel->id)->whereNull('deleted_at')->pluck('id')->all();
        $n = 0;
        foreach (Insights::for($this->hotel, $branches)->targets($this->now->format('Y-m')) as $t) {
            if ($t['revenue_progress'] !== null && $t['revenue_progress'] < $percent && $this->a->once($this->hotel, 'target_risk', "{$t['branch_id']}:{$t['month']}")) {
                $n += $this->tell('target_risk', $this->a->recipients($this->hotel, 'target_risk'), "{$t['branch']} is heading for {$t['revenue_progress']}% of this month's target", "Projection {$t['projection']} of {$t['revenue_target']}", '/reports?report=forecast');
            }
        }

        return $n;
    }

    // 32. Daily manager report is ready.
    private function dailyReport(): int
    {
        if (! $this->after('00:30') || ! $this->a->once($this->hotel, 'daily_report', $this->now->toDateString())) {
            return 0;
        }

        return $this->tell('daily_report', $this->a->recipients($this->hotel, 'daily_report'), "Yesterday's daily report is ready", $this->now->copy()->subDay()->format('D d M Y'), '/reports?report=daily');
    }

    // 33. Weekly summary every Monday.
    private function weeklySummary(): int
    {
        if ($this->now->dayOfWeekIso !== 1 || ! $this->after('08:00') || ! $this->a->once($this->hotel, 'weekly_summary', $this->now->toDateString())) {
            return 0;
        }
        $to = $this->utc($this->today());
        $from = $to->copy()->subDays(7);
        $bookings = Reservation::whereBetween('created_at', [$from, $to])->count();
        $money = DB::table('payments')->where('hotel_id', $this->hotel->id)->whereBetween('paid_at', [$from, $to])
            ->selectRaw("currency, SUM(CASE WHEN type = 'refund' THEN -amount ELSE amount END) as t")->groupBy('currency')->pluck('t', 'currency')
            ->map(fn ($v, $c) => "{$c} ".Money::round((string) $v))->implode(', ');
        $stays = Reservation::where('status', 'checked_out')->whereBetween('check_out_at', [$from, $to])->count();

        return $this->tell('weekly_summary', $this->a->recipients($this->hotel, 'weekly_summary'), "Last week: {$bookings} new bookings, {$stays} stays finished", 'Money taken: '.($money ?: '0'), '/reports');
    }
}

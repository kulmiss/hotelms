<?php

namespace App\Services\Reports;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\CustomReport;
use App\Models\ExchangeRate;
use App\Models\Hotel;
use App\Models\HotelCurrency;
use App\Models\Reservation;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\Notify\Notifier;
use App\Support\HotelClock;
use App\Support\Money;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Scheduled reports and smart alerts (reports.md features 5 and 17).
 * Runs from cron inside the hotel's tenant context.
 */
class Automation
{
    /** Default alert rules with their thresholds. */
    public const ALERTS = [
        'cash_short' => ['amount' => 200],
        'unusual_discounts' => ['factor' => 2],
        'slow_booking' => ['points' => 20, 'days' => 14],
        'pending_expiring' => ['hours' => 3],
        'target_risk' => ['percent' => 85],
        'rate_stale' => ['days' => 3],
    ];

    /** Reports that can be scheduled. */
    public const SCHEDULABLE = ['daily', 'performance', 'payments', 'outstanding', 'tax', 'cancellations', 'branches', 'custom'];

    public function __construct(private Notifier $notifier) {}

    /** Every hotel starts with all alerts on (in the app) and a nightly manager report by email to the Hotel Admins. */
    public function ensureDefaults(Hotel $hotel): void
    {
        foreach (self::ALERTS as $type => $threshold) {
            AlertRule::firstOrCreate(['type' => $type], ['threshold' => $threshold, 'channels' => ['app'], 'recipients' => [], 'is_active' => true]);
        }
        if (! ReportSchedule::where('report_key', 'daily')->exists()) {
            $s = ReportSchedule::create([
                'name' => 'Daily manager report', 'report_key' => 'daily', 'frequency' => 'daily', 'time' => '00:30',
                'channels' => ['email'], 'recipients' => $this->hotelAdmins($hotel)->pluck('id')->all(), 'is_active' => true,
            ]);
            $s->update(['next_run_at' => $this->nextRun($s, $hotel)]);
        }
    }

    // ------------------------------------------------------------------ schedules

    /** Next time (UTC) a schedule should send, after $after. */
    public function nextRun(ReportSchedule $s, Hotel $hotel, ?Carbon $after = null): Carbon
    {
        $zone = HotelClock::zone($hotel);
        $after = ($after ?? now())->copy()->setTimezone($zone);
        [$h, $m] = array_map('intval', explode(':', $s->time ?: '07:00'));
        $c = $after->copy()->setTime($h, $m);

        if ($s->frequency === 'weekly') {
            $day = max(1, min(7, (int) ($s->day ?: 1)));
            while ($c->isoWeekday() !== $day || $c->lte($after)) {
                $c->addDay()->setTime($h, $m);
            }
        } elseif ($s->frequency === 'monthly') {
            $day = max(1, min(28, (int) ($s->day ?: 1)));
            $c = $after->copy()->setDay($day)->setTime($h, $m);
            if ($c->lte($after)) {
                $c = $after->copy()->addMonthNoOverflow()->setDay($day)->setTime($h, $m);
            }
        } elseif ($c->lte($after)) {
            $c->addDay();
        }

        return $c->utc();
    }

    /** The period a schedule covers when it runs: yesterday, the last 7 days, or last month. */
    public function period(ReportSchedule $s, Hotel $hotel): array
    {
        $today = HotelClock::now($hotel)->startOfDay();
        $yesterday = $today->copy()->subDay();

        return match ($s->frequency) {
            'weekly' => [$today->copy()->subDays(7)->toDateString(), $yesterday->toDateString()],
            'monthly' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => [$yesterday->toDateString(), $yesterday->toDateString()],
        };
    }

    /**
     * Title, short summary lines and the Excel file for a schedule.
     *
     * @return array{title: string, lines: list<string>, file: string, filename: string, link: string}
     */
    public function build(ReportSchedule $s, Hotel $hotel): array
    {
        [$from, $to] = $this->period($s, $hotel);
        $branchIds = $s->branch_id ? [$s->branch_id] : DB::table('branches')->where('hotel_id', $hotel->id)->whereNull('deleted_at')->pluck('id')->all();
        $m = Metrics::for($hotel, $branchIds);
        $c = $hotel->default_currency ?? 'ETB';
        $range = $from === $to ? $from : "{$from} – {$to}";
        $k = $m->kpis($from, $to);
        $kpiLines = ["Occupancy {$k['occupancy']}%", "Revenue {$c} {$k['revenue']}", "ADR {$c} {$k['adr']}", "Money received {$c} {$k['payments']}"];

        [$title, $lines, $headers, $rows] = match ($s->report_key) {
            'daily' => (function () use ($hotel, $branchIds, $from, $c, $kpiLines) {
                $d = Insights::for($hotel, $branchIds)->daily($from);

                return ['Daily manager report', array_merge($kpiLines, [
                    "Arrived {$d['arrivals']} · left {$d['departures']} · no-shows {$d['no_shows']}",
                    "Watch: discounts {$c} {$d['watch']['discounts']}, voids {$d['watch']['voids']}, refunds {$c} {$d['watch']['refunds']}, unpaid {$c} {$d['watch']['unpaid']}",
                    "Tomorrow: {$d['tomorrow']['arrivals']} arrivals, {$d['tomorrow']['occupancy']}% booked",
                ]), ['Measure', 'Value', 'Same day last week'], collect($d['kpis'])->map(fn ($v, $key) => [$key, $v, $d['last_week'][$key] ?? ''])->values()->all()];
            })(),
            'payments' => (function () use ($m, $from, $to, $c) {
                $p = $m->paymentsSummary($from, $to);

                return ['Payments', ["Total received {$c} {$p['total']}"], ['Method', 'Type', 'Currency', 'Count', 'Amount', 'In main currency'], array_map('array_values', $p['by_method'])];
            })(),
            'outstanding' => (function () use ($m, $c) {
                $rows = $m->outstanding();

                return ['Outstanding balances', [count($rows).' unpaid bills, '.$c.' '.Money::sum(array_column($rows, 'balance'))], ['Invoice', 'Guest', 'Phone', 'Booking', 'Issued', 'Age (days)', 'Balance'],
                    array_map(fn ($r) => [$r['number'], $r['guest'], $r['phone'], $r['reservation'], $r['issued_at'], $r['age_days'], $r['balance']], $rows)];
            })(),
            'tax' => (function () use ($m, $from, $to) {
                $rows = $m->tax($from, $to);

                return ['Tax', array_map(fn ($r) => "{$r['name']} {$r['rate']}%: {$r['tax']} on {$r['base']}", $rows), ['Tax', 'Rate %', 'Included', 'Base', 'Tax', 'Invoices'],
                    array_map(fn ($r) => [$r['name'], $r['rate'], $r['is_inclusive'] ? 'yes' : 'no', $r['base'], $r['tax'], $r['invoices']], $rows)];
            })(),
            'cancellations' => (function () use ($m, $from, $to, $c) {
                $x = $m->cancellations($from, $to);

                return ['Cancellations and no-shows', ["Cancelled {$x['cancelled']}, expired {$x['expired']}, no-shows {$x['no_show']}, fees {$c} {$x['fees']}"], ['Booking', 'Guest', 'Status', 'Reason', 'Check-in', 'Fee'],
                    array_map(fn ($r) => [$r['code'], $r['guest'], $r['status'], $r['reason'], $r['check_in'], $r['fee']], $x['rows'])];
            })(),
            'branches' => (function () use ($hotel, $branchIds, $from, $to) {
                $rows = DB::table('branches')->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($b) => ['branch' => $b->name] + Metrics::for($hotel, [$b->id])->kpis($from, $to))->all();

                return ['Branch comparison', array_map(fn ($r) => "{$r['branch']}: {$r['occupancy']}%, revenue {$r['revenue']}", $rows), ['Branch', 'Occupancy %', 'ADR', 'RevPAR', 'Revenue', 'Payments'],
                    array_map(fn ($r) => [$r['branch'], $r['occupancy'], $r['adr'], $r['revpar'], $r['revenue'], $r['payments']], $rows)];
            })(),
            'custom' => (function () use ($s, $hotel, $branchIds, $from, $to) {
                $report = CustomReport::find($s->custom_report_id);
                $result = $report ? (new CustomReportEngine($hotel, $branchIds))->run($report->measures, $report->group_by, $from, $to) : ['columns' => [], 'rows' => []];

                return [$report?->name ?? 'Custom report', [count($result['rows']).' rows'], array_merge([$report?->group_by ?? 'Group'], $result['columns']),
                    array_map(fn ($r) => array_merge([$r['label']], array_map(fn ($col) => $r[$col] ?? '—', $result['columns'])), $result['rows'])];
            })(),
            default => ['Revenue and performance', $kpiLines, ['Date', 'Room nights', 'Occupancy %', 'Room revenue', 'ADR', 'Revenue', 'Payments'], array_map('array_values', $m->daily($from, $to))],
        };

        return [
            'title' => "{$title} · {$range}",
            'lines' => $lines,
            'file' => Spreadsheet::xlsx(mb_substr($title, 0, 31), $headers, $rows),
            'filename' => str_replace(' ', '-', strtolower($title))."-{$from}.xlsx",
            'link' => '/reports',
        ];
    }

    /** Send one schedule now. Returns the problems (empty when all was sent). */
    public function send(ReportSchedule $s, Hotel $hotel): array
    {
        try {
            $r = $this->build($s, $hotel);
            $problems = $this->notifier->send($hotel, $this->recipients($hotel, $s->recipients), $s->channels, $s->extra_emails ?? [], $r['title'], $r['lines'], $r['filename'], $r['file'], $r['link']);
        } catch (Throwable $e) {
            $problems = ['Could not build the report: '.$e->getMessage()];
        }
        $s->update(['last_sent_at' => now(), 'last_error' => $problems ? mb_substr(implode(' ', $problems), 0, 300) : null]);

        return $problems;
    }

    /** Send every schedule that is due; then plan its next run. */
    public function runDue(Hotel $hotel): int
    {
        $count = 0;
        foreach (ReportSchedule::where('is_active', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->get() as $s) {
            $this->send($s, $hotel);
            $s->update(['next_run_at' => $this->nextRun($s, $hotel)]);
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------------ smart alerts

    /** Check every active rule; new problems become alert events and are sent. Returns new events. */
    public function checkAlerts(Hotel $hotel): int
    {
        $new = 0;
        $branchIds = DB::table('branches')->where('hotel_id', $hotel->id)->whereNull('deleted_at')->pluck('id')->all();
        foreach (AlertRule::where('is_active', true)->get() as $rule) {
            $t = ($rule->threshold ?? []) + (self::ALERTS[$rule->type] ?? []);
            $found = match ($rule->type) {
                'cash_short' => $this->cashShort($hotel, $t),
                'unusual_discounts' => $this->unusualDiscounts($hotel, $t),
                'slow_booking' => $this->slowBooking($hotel, $branchIds, $t),
                'pending_expiring' => $this->pendingExpiring($hotel, $t),
                'target_risk' => $this->targetRisk($hotel, $branchIds, $t),
                'rate_stale' => $this->rateStale($hotel, $t),
                default => [],
            };
            foreach ($found as $f) {
                $event = AlertEvent::firstOrCreate(
                    ['alert_rule_id' => $rule->id, 'fingerprint' => $f['fingerprint']],
                    ['title' => $f['title'], 'payload' => $f['payload'] ?? null, 'link' => $f['link'] ?? null, 'fired_at' => now()],
                );
                if ($event->wasRecentlyCreated) {
                    $new++;
                    $channels = array_values(array_intersect($rule->channels ?? [], ['email', 'telegram']));
                    if ($channels) {
                        $this->notifier->send($hotel, $this->recipients($hotel, $rule->recipients), $channels, [], 'Alert: '.$f['title'], [$f['title']], null, null, $f['link'] ?? null);
                    }
                }
            }
            $rule->update(['last_checked_at' => now()]);
        }

        return $new;
    }

    private function cashShort(Hotel $hotel, array $t): array
    {
        return DB::table('cashier_session_balances as b')->join('cashier_sessions as s', 's.id', '=', 'b.cashier_session_id')->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.hotel_id', $hotel->id)->where('s.closed_at', '>=', now()->subDay())->where('b.difference', '<', -abs((float) $t['amount']))
            ->get(['s.id', 'b.currency', 'b.difference', 'u.name'])
            ->map(fn ($r) => ['fingerprint' => "cash:{$r->id}:{$r->currency}", 'title' => "Cash short {$r->currency} ".abs((float) $r->difference)." on {$r->name}'s shift", 'link' => '/cashier'])->all();
    }

    private function unusualDiscounts(Hotel $hotel, array $t): array
    {
        $rows = DB::table('invoice_items as it')->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')->where('inv.hotel_id', $hotel->id)
            ->where('it.type', 'discount')->where('it.created_at', '>=', now()->subDays(7))->whereNotNull('it.created_by')
            ->selectRaw('it.created_by as u, SUM(-it.amount) as s')->groupBy('it.created_by')->pluck('s', 'u');
        if ($rows->count() < 2) {
            return [];
        }
        $avg = $rows->avg();
        $week = now()->format('o-W');
        $names = User::whereIn('id', $rows->keys())->pluck('name', 'id');

        return $rows->filter(fn ($v) => $avg > 0 && $v > $avg * (float) $t['factor'])
            ->map(fn ($v, $u) => ['fingerprint' => "disc:{$u}:{$week}", 'title' => ($names[$u] ?? 'Someone')." gave unusual discounts this week ({$hotel->default_currency} ".Money::round((string) $v).')', 'link' => '/reports?report=audit'])
            ->values()->all();
    }

    private function slowBooking(Hotel $hotel, array $branchIds, array $t): array
    {
        $f = Insights::for($hotel, $branchIds)->forecast((int) $t['days']);

        return collect($f['days'])->filter(fn ($d) => $d['usual_occupancy'] - $d['occupancy'] >= (float) $t['points'])
            ->map(fn ($d) => ['fingerprint' => "slow:{$d['date']}", 'title' => "Slow booking for {$d['date']}: {$d['occupancy']}% booked, usually {$d['usual_occupancy']}% by now", 'link' => '/reports?report=forecast'])
            ->values()->all();
    }

    private function pendingExpiring(Hotel $hotel, array $t): array
    {
        return Reservation::with('guest:id,first_name,last_name')->where('status', 'pending')->where('expires_at', '<=', now()->addHours((int) $t['hours']))->where('expires_at', '>', now())->get()
            ->map(fn (Reservation $r) => ['fingerprint' => "pend:{$r->id}", 'title' => "Booking {$r->code} ({$r->guest?->full_name}) expires at ".HotelClock::local($r->expires_at, $hotel)->format('H:i').' without a deposit', 'link' => "/reservations/{$r->id}"])
            ->all();
    }

    private function targetRisk(Hotel $hotel, array $branchIds, array $t): array
    {
        $now = HotelClock::now($hotel);
        if ($now->day < 7) {
            return []; // too early in the month to tell
        }
        $month = $now->format('Y-m');

        return collect(Insights::for($hotel, $branchIds)->targets($month))
            ->filter(fn ($r) => $r['revenue_progress'] !== null && $r['revenue_progress'] < (float) $t['percent'])
            ->map(fn ($r) => ['fingerprint' => "target:{$r['branch_id']}:{$month}", 'title' => "{$r['branch']}: revenue heading to {$r['revenue_progress']}% of this month's target", 'link' => '/reports?report=forecast'])
            ->values()->all();
    }

    private function rateStale(Hotel $hotel, array $t): array
    {
        $main = $hotel->default_currency ?? 'ETB';
        $others = HotelCurrency::where('code', '!=', $main)->pluck('code');
        $out = [];
        foreach ($others as $code) {
            $last = ExchangeRate::where('from_currency', $code)->max('effective_date');
            if (! $last || Carbon::parse($last)->lt(now()->subDays((int) $t['days']))) {
                $out[] = ['fingerprint' => "rate:{$code}:".now()->toDateString(), 'title' => "The {$code} exchange rate was not updated for ".(int) $t['days'].'+ days', 'link' => '/settings/currencies'];
            }
        }

        return $out;
    }

    /** @return Collection<int, User> */
    private function hotelAdmins(Hotel $hotel): Collection
    {
        return User::where('hotel_id', $hotel->id)->where('status', 'active')->whereHas('role', fn ($q) => $q->where('key', 'hotel_admin'))->get();
    }

    /** @param  list<int>|null  $ids  empty = the Hotel Admins */
    private function recipients(Hotel $hotel, ?array $ids): Collection
    {
        return $ids ? User::where('hotel_id', $hotel->id)->where('status', 'active')->whereIn('id', $ids)->get() : $this->hotelAdmins($hotel);
    }
}

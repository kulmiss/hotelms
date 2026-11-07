<?php

namespace App\Services\Reports;

use App\Models\ActivityLog;
use App\Models\CashierSession;
use App\Models\Hotel;
use App\Models\RateRule;
use App\Models\ReportTarget;
use App\Models\User;
use App\Services\Modules\Cashier;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily manager report, forecast and pickup, targets, staff audit, personal summaries
 * and the accounting export (reports.md features 4, 6, 7, 10, 13, 19, 20).
 */
class Insights
{
    private Metrics $metrics;

    /** @param  list<int>  $branchIds */
    public function __construct(private Hotel $hotel, private array $branchIds)
    {
        $this->metrics = Metrics::for($hotel, $branchIds);
    }

    public static function for(Hotel $hotel, array $branchIds): self
    {
        return new self($hotel, array_values(array_map('intval', $branchIds)));
    }

    private function utc(string $date): Carbon
    {
        return Carbon::parse($date, HotelClock::zone($this->hotel))->startOfDay()->utc();
    }

    private function between(string $from, string $to): array
    {
        return [$this->utc($from), $this->utc($to)->addDay()];
    }

    private function onBooks(): Builder
    {
        return DB::table('reservation_room_rates as r')->join('reservation_rooms as rr', 'rr.id', '=', 'r.reservation_room_id')
            ->join('reservations as res', 'res.id', '=', 'rr.reservation_id')
            ->where('res.hotel_id', $this->hotel->id)->whereIn('res.branch_id', $this->branchIds)->where('r.label', 'Night');
    }

    // ------------------------------------------------------------------ daily manager report

    /** One page for a day, compared with the same weekday last week (feature 4). */
    public function daily(string $date): array
    {
        $week = Carbon::parse($date)->subWeek()->toDateString();
        $tomorrow = Carbon::parse($date)->addDay()->toDateString();
        [$start, $end] = $this->between($date, $date);
        $res = fn () => DB::table('reservations')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)->whereNull('deleted_at');
        $items = fn () => DB::table('invoice_items as it')->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')->where('inv.hotel_id', $this->hotel->id)->whereIn('inv.branch_id', $this->branchIds);

        $cashier = CashierSession::with(['user:id,name', 'balances'])->whereIn('branch_id', $this->branchIds)->whereBetween('opened_at', [$start, $end])->get()
            ->map(fn ($s) => ['user' => $s->user?->name, 'status' => $s->status, 'differences' => $s->balances->filter(fn ($b) => $b->difference !== null)->map(fn ($b) => ['currency' => $b->currency, 'difference' => (string) $b->difference])->values()]);

        $rooms = $this->metrics->roomCount();
        $tomorrowNights = (clone $this->onBooks())->whereIn('rr.status', ['pending', 'confirmed', 'checked_in'])->where('r.date', $tomorrow)->count();

        return [
            'date' => $date,
            'kpis' => $this->metrics->kpis($date, $date),
            'last_week' => $this->metrics->kpis($week, $week),
            'arrivals' => $res()->whereIn('status', ['checked_in', 'checked_out'])->whereBetween('check_in_at', [$start, $end])->count(),
            'departures' => $res()->where('status', 'checked_out')->whereBetween('check_out_at', [$start, $end])->count(),
            'no_shows' => $res()->where('status', 'no_show')->whereBetween('cancelled_at', [$start, $end])->count(),
            'walk_ins' => $res()->where('source', 'walk_in')->whereBetween('created_at', [$start, $end])->count(),
            'payments' => $this->metrics->paymentsSummary($date, $date)['by_method'],
            'cashier' => $cashier->values(),
            'watch' => [
                'discounts' => Money::round((string) ($items()->where('it.type', 'discount')->whereBetween('it.created_at', [$start, $end])->sum('it.amount') ?: '0')),
                'waived' => $items()->where('it.is_waived', true)->whereBetween('it.created_at', [$start, $end])->count(),
                'voids' => DB::table('invoices')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)->whereBetween('voided_at', [$start, $end])->count(),
                'refunds' => Money::round((string) (DB::table('payments')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)->where('type', 'refund')->whereBetween('paid_at', [$start, $end])->sum('amount_in_base') ?: '0')),
                'unpaid' => Money::round((string) (DB::table('invoices')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)->where('status', '!=', 'void')->where('balance', '>', 0)->sum('balance') ?: '0')),
            ],
            'tomorrow' => [
                'date' => $tomorrow,
                'arrivals' => $res()->whereIn('status', ['pending', 'confirmed'])->whereBetween('check_in_at', $this->between($tomorrow, $tomorrow))->count(),
                'departures' => $res()->whereIn('status', ['checked_in', 'confirmed'])->whereBetween('check_out_at', $this->between($tomorrow, $tomorrow))->count(),
                'occupancy' => $rooms > 0 ? round($tomorrowNights / $rooms * 100, 1) : 0.0,
            ],
        ];
    }

    // ------------------------------------------------------------------ forecast and pickup

    /**
     * Next N days on the books, with "usually by now" (same weekday a year earlier, booked by the same lead time),
     * season rules on each day, and bookings made per day for the last 30 days (pickup).
     */
    public function forecast(int $days): array
    {
        $today = HotelClock::now($this->hotel)->toDateString();
        $last = Carbon::parse($today)->addDays($days - 1)->toDateString();
        $rooms = $this->metrics->roomCount();
        $booked = (clone $this->onBooks())->whereIn('rr.status', ['pending', 'confirmed', 'checked_in', 'checked_out'])
            ->whereBetween('r.date', [$today, $last])->selectRaw('r.date as d, COUNT(*) as n, SUM(r.amount) as v')->groupBy('r.date')->get()->keyBy('d');
        $arrivals = DB::table('reservations')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)->whereIn('status', ['pending', 'confirmed'])
            ->where('check_in_at', '>=', $this->utc($today))->where('check_in_at', '<', $this->utc($last)->addDay())
            ->selectRaw('DATE(DATE_ADD(check_in_at, INTERVAL '.(int) HotelClock::now($this->hotel)->utcOffset().' MINUTE)) as d, COUNT(*) as n')->groupByRaw('d')->pluck('n', 'd');

        // "Usually by now": 52 weeks earlier, counting only what had been booked at the same lead time.
        // One question for the whole period a year ago, then counted per day here.
        $lyNights = (clone $this->onBooks())->whereIn('rr.status', ['checked_in', 'checked_out', 'confirmed', 'pending', 'cancelled', 'no_show'])
            ->whereBetween('r.date', [Carbon::parse($today)->subWeeks(52)->toDateString(), Carbon::parse($last)->subWeeks(52)->toDateString()])
            ->get(['r.date as d', 'res.created_at as made', 'res.cancelled_at as gone'])->groupBy(fn ($row) => substr((string) $row->d, 0, 10));
        $usual = [];
        foreach (Metrics::days($today, $last) as $d) {
            $ly = Carbon::parse($d)->subWeeks(52);
            $lead = Carbon::parse($today)->diffInDays(Carbon::parse($d));
            $by = $this->utc($ly->copy()->subDays($lead)->toDateString())->addDay(); // the same lead time, a year ago
            $usual[$d] = ($lyNights[$ly->toDateString()] ?? collect())
                ->filter(fn ($row) => Carbon::parse($row->made, 'UTC')->lt($by) && ($row->gone === null || Carbon::parse($row->gone, 'UTC')->gt($by)))
                ->count();
        }
        $seasons = RateRule::where('is_active', true)->where('rule_type', 'season')->where('end_date', '>=', $today)->where('start_date', '<=', $last)->get(['name', 'start_date', 'end_date']);

        $rows = array_map(function (string $d) use ($rooms, $booked, $arrivals, $usual, $seasons) {
            $n = (int) ($booked[$d]->n ?? 0);

            return [
                'date' => $d,
                'room_nights' => $n,
                'occupancy' => $rooms > 0 ? round($n / $rooms * 100, 1) : 0.0,
                'usual_occupancy' => $rooms > 0 ? round($usual[$d] / $rooms * 100, 1) : 0.0,
                'room_revenue' => Money::round((string) ($booked[$d]->v ?? '0')),
                'arrivals' => (int) ($arrivals[$d] ?? 0),
                'season' => $seasons->first(fn ($s) => $d >= $s->start_date->toDateString() && $d <= $s->end_date->toDateString())?->name,
            ];
        }, Metrics::days($today, $last));

        $pickupFrom = Carbon::parse($today)->subDays(29)->toDateString();
        $offset = (int) HotelClock::now($this->hotel)->utcOffset();
        $pickup = DB::table('reservations as res')->join('reservation_rooms as rr', 'rr.reservation_id', '=', 'res.id')->join('reservation_room_rates as r', 'r.reservation_room_id', '=', 'rr.id')
            ->where('res.hotel_id', $this->hotel->id)->whereIn('res.branch_id', $this->branchIds)->where('r.label', 'Night')
            ->whereBetween('res.created_at', $this->between($pickupFrom, $today))
            ->selectRaw("DATE(DATE_ADD(res.created_at, INTERVAL {$offset} MINUTE)) as d, COUNT(DISTINCT res.id) as bookings, COUNT(*) as nights")->groupByRaw('d')->get()->keyBy('d');

        return [
            'rooms' => $rooms,
            'days' => $rows,
            'pickup' => array_map(fn ($d) => ['date' => $d, 'bookings' => (int) ($pickup[$d]->bookings ?? 0), 'room_nights' => (int) ($pickup[$d]->nights ?? 0)], Metrics::days($pickupFrom, $today)),
        ];
    }

    // ------------------------------------------------------------------ targets

    /** Targets vs actual for a month: earned so far + already booked = projection (feature 7). */
    public function targets(string $month): array
    {
        $first = Carbon::parse($month.'-01');
        $from = $first->toDateString();
        $to = $first->copy()->endOfMonth()->toDateString();
        $today = HotelClock::now($this->hotel)->toDateString();

        return DB::table('branches')->whereIn('id', $this->branchIds)->orderBy('name')->get(['id', 'name'])->map(function ($b) use ($month, $from, $to, $today) {
            $m = Metrics::for($this->hotel, [$b->id]);
            $pastEnd = min($today, $to);
            $earned = $from <= $pastEnd ? $m->kpis($from, $pastEnd) : null;
            $futureFrom = max($from, Carbon::parse($today)->addDay()->toDateString());
            $booked = $futureFrom <= $to
                ? DB::table('reservation_room_rates as r')->join('reservation_rooms as rr', 'rr.id', '=', 'r.reservation_room_id')->join('reservations as res', 'res.id', '=', 'rr.reservation_id')
                    ->where('res.hotel_id', $this->hotel->id)->where('res.branch_id', $b->id)->whereIn('rr.status', ['pending', 'confirmed', 'checked_in'])
                    ->where('r.label', 'Night')->whereBetween('r.date', [$futureFrom, $to])->selectRaw('COUNT(*) as n, SUM(r.amount) as v')->first()
                : null;
            $target = ReportTarget::where('branch_id', $b->id)->where('month', $month)->first();
            $earnedRevenue = $earned['revenue'] ?? '0.00';
            $bookedRevenue = Money::round((string) ($booked->v ?? '0'));
            $projection = Money::add($earnedRevenue, $bookedRevenue);
            $nights = ($earned['room_nights'] ?? 0) + (int) ($booked->n ?? 0);
            $available = $m->roomCount() * (Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);

            return [
                'branch_id' => $b->id, 'branch' => $b->name, 'month' => $month,
                'revenue_target' => $target?->revenue_target, 'occupancy_target' => $target?->occupancy_target,
                'earned' => $earnedRevenue, 'booked' => $bookedRevenue, 'projection' => $projection,
                'projected_occupancy' => $available > 0 ? round($nights / $available * 100, 1) : 0.0,
                'revenue_progress' => $target?->revenue_target && Money::isPositive((string) $target->revenue_target) ? round((float) $projection / (float) $target->revenue_target * 100, 1) : null,
            ];
        })->all();
    }

    // ------------------------------------------------------------------ staff audit

    /** Discounts, waived fees, voids, refunds and cashier differences per person, with flags (feature 10). */
    public function audit(string $from, string $to): array
    {
        [$start, $end] = $this->between($from, $to);
        $hotel = $this->hotel->id;
        $items = fn () => DB::table('invoice_items as it')->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')->where('inv.hotel_id', $hotel)->whereIn('inv.branch_id', $this->branchIds)->whereBetween('it.created_at', [$start, $end]);

        $discounts = $items()->where('it.type', 'discount')->selectRaw('it.created_by as u, COUNT(*) as c, SUM(-it.amount) as s')->groupBy('it.created_by')->get()->keyBy('u');
        $bookingDiscounts = DB::table('reservations')->where('hotel_id', $hotel)->whereIn('branch_id', $this->branchIds)->whereBetween('created_at', [$start, $end])
            ->where('discount_amount', '>', 0)->selectRaw('created_by as u, COUNT(*) as c, SUM(discount_amount) as s')->groupBy('created_by')->get()->keyBy('u');
        $waived = $items()->where('it.is_waived', true)->selectRaw('it.waived_by as u, COUNT(*) as c, SUM(it.unit_price) as s')->groupBy('it.waived_by')->get()->keyBy('u');
        $voids = DB::table('invoices')->where('hotel_id', $hotel)->whereIn('branch_id', $this->branchIds)->whereBetween('voided_at', [$start, $end])
            ->selectRaw('voided_by as u, COUNT(*) as c, SUM(total) as s')->groupBy('voided_by')->get()->keyBy('u');
        $refunds = DB::table('payments')->where('hotel_id', $hotel)->whereIn('branch_id', $this->branchIds)->where('type', 'refund')->whereBetween('paid_at', [$start, $end])
            ->selectRaw('received_by as u, COUNT(*) as c, SUM(amount_in_base) as s')->groupBy('received_by')->get()->keyBy('u');
        $cash = DB::table('cashier_session_balances as b')->join('cashier_sessions as s', 's.id', '=', 'b.cashier_session_id')->where('s.hotel_id', $hotel)->whereIn('s.branch_id', $this->branchIds)
            ->whereBetween('s.closed_at', [$start, $end])->whereNotNull('b.difference')
            ->selectRaw('s.user_id as u, SUM(CASE WHEN b.difference < 0 THEN b.difference ELSE 0 END) as short, SUM(CASE WHEN b.difference > 0 THEN b.difference ELSE 0 END) as over_amount, COUNT(*) as c')
            ->groupBy('s.user_id')->get()->keyBy('u');

        $ids = collect([$discounts, $bookingDiscounts, $waived, $voids, $refunds, $cash])->flatMap(fn ($c) => $c->keys())->filter()->unique()->values();
        $users = User::whereIn('id', $ids)->where('hotel_id', $hotel)->pluck('name', 'id');
        $val = fn ($c, $id) => Money::round((string) ($c[$id]->s ?? '0'));
        $rows = $ids->map(fn ($id) => [
            'user_id' => (int) $id,
            'name' => $users[$id] ?? '—',
            'discounts' => Money::add($val($discounts, $id), $val($bookingDiscounts, $id)),
            'discount_count' => (int) (($discounts[$id]->c ?? 0) + ($bookingDiscounts[$id]->c ?? 0)),
            'waived' => $val($waived, $id),
            'waived_count' => (int) ($waived[$id]->c ?? 0),
            'voids' => (int) ($voids[$id]->c ?? 0),
            'void_value' => $val($voids, $id),
            'refunds' => $val($refunds, $id),
            'refund_count' => (int) ($refunds[$id]->c ?? 0),
            'cash_short' => Money::round((string) ($cash[$id]->short ?? '0')),
            'cash_over' => Money::round((string) ($cash[$id]->over_amount ?? '0')),
        ])->values();

        // Flags: discounts twice the team's average, cash short more than 200, three or more voids.
        $avg = $rows->count() > 1 ? $rows->avg(fn ($r) => (float) $r['discounts']) : null;
        $rows = $rows->map(function ($r) use ($avg) {
            $flags = [];
            if ($avg && (float) $r['discounts'] > 2 * $avg && (float) $r['discounts'] > 0) {
                $flags[] = 'discounts';
            }
            if ((float) $r['cash_short'] < -200) {
                $flags[] = 'cash_short';
            }
            if ($r['voids'] >= 3) {
                $flags[] = 'voids';
            }

            return $r + ['flags' => $flags];
        });

        return ['rows' => $rows->sortByDesc(fn ($r) => count($r['flags']))->values()->all()];
    }

    /** Everything one person did in a time window, from the activity log. */
    public function timeline(int $userId, Carbon $start, Carbon $end, int $limit = 200): array
    {
        return ActivityLog::where('user_id', $userId)->whereBetween('created_at', [$start, $end])->latest()->limit($limit)->get(['id', 'event', 'description', 'created_at'])
            ->map(fn ($a) => ['id' => $a->id, 'event' => $a->event, 'description' => $a->description, 'time' => HotelClock::iso($a->created_at, $this->hotel)])->all();
    }

    // ------------------------------------------------------------------ my shift / my work

    /** Receptionist's own shift (feature 19): from the open or chosen cashier shift, or today. */
    public function myShift(User $user, ?int $sessionId): array
    {
        $session = $sessionId
            ? CashierSession::with('balances')->where('user_id', $user->id)->find($sessionId)
            : CashierSession::with('balances')->where('user_id', $user->id)->latest('opened_at')->first();
        $today = HotelClock::now($this->hotel);
        $useSession = $session && ($sessionId || $session->status === 'open' || $session->opened_at->gte($today->copy()->startOfDay()->utc()));
        $start = $useSession ? $session->opened_at : $today->copy()->startOfDay()->utc();
        $end = $useSession && $session->closed_at ? $session->closed_at : now();
        $res = fn () => DB::table('reservations')->where('hotel_id', $this->hotel->id);
        $items = fn () => DB::table('invoice_items as it')->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')->where('inv.hotel_id', $this->hotel->id)->whereBetween('it.created_at', [$start, $end]);

        $payments = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->where('p.hotel_id', $this->hotel->id)
            ->where('p.received_by', $user->id)->whereBetween('p.paid_at', [$start, $end])
            ->selectRaw("m.name as method, p.currency, COUNT(*) as count, SUM(CASE WHEN p.type = 'refund' THEN -p.amount ELSE p.amount END) as amount, SUM(CASE WHEN p.type = 'refund' THEN -p.amount_in_base ELSE p.amount_in_base END) as base")
            ->groupBy('m.name', 'p.currency')->get()
            ->map(fn ($r) => ['method' => $r->method, 'currency' => $r->currency, 'count' => (int) $r->count, 'amount' => Money::round((string) $r->amount), 'amount_in_base' => Money::round((string) $r->base)])->all();

        $sessions = CashierSession::where('user_id', $user->id)->where('opened_at', '>=', now()->subDays(30))->latest('opened_at')->get(['id', 'opened_at', 'closed_at', 'status'])
            ->map(fn ($s) => ['id' => $s->id, 'opened_at' => HotelClock::iso($s->opened_at, $this->hotel), 'closed_at' => HotelClock::iso($s->closed_at, $this->hotel), 'status' => $s->status])->all();

        return [
            'from' => HotelClock::iso($start, $this->hotel),
            'to' => HotelClock::iso($end, $this->hotel),
            'session' => $useSession ? ['id' => $session->id, 'status' => $session->status, 'balances' => $session->balances, 'expected' => $session->status === 'open' ? app(Cashier::class)->expected($session) : null] : null,
            'sessions' => $sessions,
            'check_ins' => $res()->where('checked_in_by', $user->id)->whereExists(fn ($q) => $q->from('reservation_rooms')->whereColumn('reservation_rooms.reservation_id', 'reservations.id')->whereBetween('actual_check_in_at', [$start, $end]))->count(),
            'check_outs' => $res()->where('checked_out_by', $user->id)->whereExists(fn ($q) => $q->from('reservation_rooms')->whereColumn('reservation_rooms.reservation_id', 'reservations.id')->whereBetween('actual_check_out_at', [$start, $end]))->count(),
            'walk_ins' => $res()->where('created_by', $user->id)->where('source', 'walk_in')->whereBetween('created_at', [$start, $end])->count(),
            'bookings' => $res()->where('created_by', $user->id)->where('source', '!=', 'walk_in')->whereBetween('created_at', [$start, $end])->count(),
            'payments' => $payments,
            'payments_total' => Money::sum(array_column($payments, 'amount_in_base')),
            'discounts' => Money::add(
                Money::round((string) ($items()->where('it.created_by', $user->id)->where('it.type', 'discount')->sum(DB::raw('-it.amount')) ?: '0')),
                Money::round((string) ($res()->where('created_by', $user->id)->whereBetween('created_at', [$start, $end])->sum('discount_amount') ?: '0')),
            ),
            'max_discount_percent' => (string) ($user->role?->max_discount_percent ?? '0'),
            'waived' => $items()->where('it.waived_by', $user->id)->count(),
            'timeline' => $this->timeline($user->id, Carbon::instance($start), Carbon::instance($end)),
        ];
    }

    /** Housekeeper's own work (feature 20): rooms cleaned, minutes per room vs the team, rooms per day. */
    public function myWork(User $user, string $period): array
    {
        $now = HotelClock::now($this->hotel);
        $from = match ($period) {
            'week' => $now->copy()->startOfWeek(),
            'month' => $now->copy()->startOfMonth(),
            default => $now->copy()->startOfDay(),
        };
        $start = $from->copy()->utc();
        $end = now();
        $tasks = fn () => DB::table('housekeeping_tasks')->where('hotel_id', $this->hotel->id)->where('status', 'done')->whereBetween('completed_at', [$start, $end]);
        $minutes = 'AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at))';
        $mine = $tasks()->where('completed_by', $user->id);
        $offset = (int) $now->utcOffset();
        $weekStart = $now->copy()->startOfWeek();

        return [
            'period' => $period,
            'cleaned' => (clone $mine)->where('type', 'cleaning')->count(),
            'inspections' => (clone $mine)->where('type', 'inspection')->count(),
            'maintenance_reported' => DB::table('housekeeping_tasks')->where('hotel_id', $this->hotel->id)->where('created_by', $user->id)->where('type', 'maintenance')->whereBetween('created_at', [$start, $end])->count(),
            'minutes' => ($m = (clone $mine)->where('type', 'cleaning')->selectRaw("{$minutes} as m")->value('m')) !== null ? (int) round((float) $m) : null,
            'team_minutes' => ($t = $tasks()->where('type', 'cleaning')->selectRaw("{$minutes} as m")->value('m')) !== null ? (int) round((float) $t) : null,
            'per_day' => collect(Metrics::days($weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()))->map(fn ($d) => [
                'date' => $d,
                'rooms' => DB::table('housekeeping_tasks')->where('hotel_id', $this->hotel->id)->where('completed_by', $user->id)->where('status', 'done')
                    ->whereRaw("DATE(DATE_ADD(completed_at, INTERVAL {$offset} MINUTE)) = ?", [$d])->count(),
            ])->all(),
            'today' => DB::table('housekeeping_tasks as t')->join('rooms as r', 'r.id', '=', 't.room_id')->where('t.hotel_id', $this->hotel->id)
                ->where(fn ($q) => $q->where('t.completed_by', $user->id)->orWhere('t.assigned_to', $user->id))
                ->where(fn ($q) => $q->where('t.status', '!=', 'done')->orWhere('t.completed_at', '>=', $now->copy()->startOfDay()->utc()))
                ->orderBy('t.started_at')->get(['t.id', 'r.number', 't.type', 't.status', 't.started_at', 't.completed_at'])
                ->map(fn ($r) => [
                    'id' => $r->id, 'room' => $r->number, 'type' => $r->type, 'status' => $r->status,
                    'started_at' => $r->started_at ? HotelClock::iso(Carbon::parse($r->started_at, 'UTC'), $this->hotel) : null,
                    'completed_at' => $r->completed_at ? HotelClock::iso(Carbon::parse($r->completed_at, 'UTC'), $this->hotel) : null,
                    'minutes' => $r->started_at && $r->completed_at ? (int) Carbon::parse($r->started_at)->diffInMinutes(Carbon::parse($r->completed_at)) : null,
                ])->all(),
        ];
    }

    // ------------------------------------------------------------------ accounting export

    /**
     * Journal lines for the accountant (feature 13): each bill line credits revenue / tax and debits guest receivables;
     * each payment debits cash or bank and credits receivables. Debits equal credits.
     *
     * @return list<array{date: string, account: string, reference: string, description: string, debit: string, credit: string}>
     */
    public function journal(string $from, string $to): array
    {
        $lines = [];
        $items = DB::table('invoice_items as it')->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')
            ->where('inv.hotel_id', $this->hotel->id)->whereIn('inv.branch_id', $this->branchIds)->where('inv.status', '!=', 'void')
            ->whereBetween('it.service_date', [$from, $to])->orderBy('it.service_date')->orderBy('it.id')
            ->get(['it.id', 'it.type', 'it.description', 'it.amount', 'it.service_date', 'inv.number']);
        $taxes = DB::table('invoice_item_taxes')->whereIn('invoice_item_id', $items->pluck('id'))->get()->groupBy('invoice_item_id');
        $accounts = ['room' => 'Room revenue', 'extra' => 'Other revenue', 'early_check_in' => 'Fee revenue', 'late_check_out' => 'Fee revenue', 'cancellation_fee' => 'Fee revenue', 'discount' => 'Discounts given'];

        foreach ($items as $it) {
            $amount = (string) $it->amount;
            $added = Money::sum(($taxes[$it->id] ?? collect())->where('is_inclusive', false)->pluck('amount')->map(fn ($v) => (string) $v));
            $inside = Money::sum(($taxes[$it->id] ?? collect())->where('is_inclusive', true)->pluck('amount')->map(fn ($v) => (string) $v));
            $net = Money::sub($amount, $inside); // revenue without the tax inside the price
            $gross = Money::add($amount, $added);
            $ref = $it->number;
            $date = (string) $it->service_date;
            $positive = Money::compare($gross, '0') >= 0;
            // Receivable
            $lines[] = ['date' => $date, 'account' => 'Guest receivables', 'reference' => $ref, 'description' => $it->description, 'debit' => $positive ? $gross : '0.00', 'credit' => $positive ? '0.00' : Money::sub('0', $gross)];
            // Revenue (or discount)
            $lines[] = ['date' => $date, 'account' => $accounts[$it->type] ?? 'Other revenue', 'reference' => $ref, 'description' => $it->description, 'debit' => $positive ? '0.00' : Money::sub('0', $net), 'credit' => $positive ? $net : '0.00'];
            // Tax payable
            $tax = Money::add($added, $inside);
            if (Money::compare($tax, '0') !== 0) {
                $lines[] = ['date' => $date, 'account' => 'Tax payable', 'reference' => $ref, 'description' => 'Tax on '.$it->description, 'debit' => $positive ? '0.00' : Money::sub('0', $tax), 'credit' => $positive ? $tax : '0.00'];
            }
        }

        $payments = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->leftJoin('invoices as inv', 'inv.id', '=', 'p.invoice_id')
            ->leftJoin('reservations as res', 'res.id', '=', 'p.reservation_id')
            ->where('p.hotel_id', $this->hotel->id)->whereIn('p.branch_id', $this->branchIds)->whereBetween('p.paid_at', $this->between($from, $to))->orderBy('p.paid_at')
            ->get(['p.type', 'p.amount_in_base', 'p.paid_at', 'p.reference', 'm.name as method', 'm.type as method_type', 'inv.number', 'res.code']);
        foreach ($payments as $p) {
            $amount = Money::round((string) $p->amount_in_base);
            $date = HotelClock::local(Carbon::parse($p->paid_at, 'UTC'), $this->hotel)->toDateString();
            $account = $p->method_type === 'cash' ? 'Cash' : 'Bank and mobile money ('.$p->method.')';
            $ref = $p->number ?? $p->code ?? '';
            $desc = ucfirst($p->type).($p->reference ? ' '.$p->reference : '');
            $refund = $p->type === 'refund';
            // Deposits before arrival are customer advances until a bill exists.
            $other = $p->number ? 'Guest receivables' : 'Customer deposits';
            $lines[] = ['date' => $date, 'account' => $account, 'reference' => $ref, 'description' => $desc, 'debit' => $refund ? '0.00' : $amount, 'credit' => $refund ? $amount : '0.00'];
            $lines[] = ['date' => $date, 'account' => $other, 'reference' => $ref, 'description' => $desc, 'debit' => $refund ? $amount : '0.00', 'credit' => $refund ? '0.00' : $amount];
        }

        usort($lines, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $lines;
    }
}

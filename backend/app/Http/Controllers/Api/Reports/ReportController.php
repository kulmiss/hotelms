<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CashierSessionBalance;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationRoomRate;
use App\Models\Room;
use App\Models\User;
use App\Services\Reports\Metrics;
use App\Support\HotelClock;
use App\Support\Money;
use App\Support\Spreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard and the report library (reports.md). Every report shows only the branches
 * the person may use; ?format=csv|xlsx downloads the table (reports.export).
 */
class ReportController extends Controller
{
    /** Report key → permission(s) needed (any of). */
    public const REPORTS = [
        'overview' => ['reports.revenue', 'reports.occupancy'],
        'performance' => ['reports.revenue', 'reports.occupancy'],
        'payments' => ['reports.payments'],
        'outstanding' => ['reports.outstanding'],
        'tax' => ['reports.tax'],
        'guests' => ['reports.guests'],
        'cancellations' => ['reports.cancellations'],
        'branches' => ['reports.branch_compare'],
        'housekeeping' => ['reports.occupancy'],
    ];

    /** Today at a glance (screen-list.md 2.1). Money only with dashboard.view_revenue. */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;
        $branchIds = $this->branchIds($request);
        $today = HotelClock::now($hotel)->toDateString();
        $start = HotelClock::now($hotel)->startOfDay()->utc();
        $end = HotelClock::now($hotel)->endOfDay()->utc();
        $res = fn () => Reservation::whereIn('branch_id', $branchIds);
        $metrics = Metrics::for($hotel, $branchIds);
        $rooms = Room::whereIn('branch_id', $branchIds)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $inHouse = $res()->where('status', 'checked_in')->count();
        $occupiedRooms = (int) ($rooms['occupied'] ?? 0);
        $sellable = $metrics->roomCount();
        $money = $user->hasPermission('dashboard.view_revenue');

        $data = [
            'date' => $today,
            'arrivals' => $res()->whereIn('status', ['pending', 'confirmed'])->where('check_in_at', '<=', $end)->where('check_out_at', '>', $start)->count(),
            'arrived' => $res()->where('status', 'checked_in')->whereBetween('check_in_at', [$start, $end])->count(),
            'departures' => $res()->where('status', 'checked_in')->where('check_out_at', '<=', $end)->count(),
            'departed' => $res()->where('status', 'checked_out')->whereHas('rooms', fn ($q) => $q->whereBetween('actual_check_out_at', [$start, $end]))->count(),
            'in_house' => $inHouse,
            'occupancy' => $sellable > 0 ? round($occupiedRooms / $sellable * 100, 1) : 0.0,
            'rooms' => ['total' => array_sum($rooms->all()), 'by_status' => $rooms],
            'pending_expiring' => $res()->where('status', 'pending')->where('expires_at', '<=', now()->addHours(3))->count(),
            'late_arrivals' => $res()->whereIn('status', ['pending', 'confirmed'])->where('check_in_at', '<', now())->where('check_in_at', '>=', $start)->count(),
            'next_7_days' => array_map(fn ($d) => ['date' => $d, 'occupancy' => $this->onTheBooks($hotel, $branchIds, $d, $sellable)], Metrics::days($today, Carbon::parse($today)->addDays(6)->toDateString())),
        ];
        if ($money) {
            $data['revenue_today'] = $metrics->kpis($today, $today)['revenue'];
            $data['payments_today'] = Money::round((string) Payment::whereIn('branch_id', $branchIds)->whereBetween('paid_at', [$start, $end])
                ->selectRaw("SUM(CASE WHEN type = 'refund' THEN -amount_in_base ELSE amount_in_base END) as t")->value('t') ?? '0');
            $data['unpaid_balances'] = Money::round((string) Invoice::whereIn('branch_id', $branchIds)->where('status', '!=', 'void')->where('balance', '>', 0)->sum('balance') ?: '0');
            $data['cashier_shortages'] = CashierSessionBalance::whereHas('session', fn ($q) => $q->whereIn('branch_id', $branchIds)->where('closed_at', '>=', now()->subDays(7)))
                ->where('difference', '<', 0)->count();
            $month = Carbon::parse($today)->startOfMonth()->toDateString();
            $data['month'] = $metrics->kpis($month, $today);
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $key): JsonResponse|Response
    {
        abort_unless(isset(self::REPORTS[$key]), 404);
        $user = $request->user();
        abort_unless(collect(self::REPORTS[$key])->contains(fn ($p) => $user->hasPermission($p)), 403, 'You do not have permission to see this report.');
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'compare' => ['nullable', 'in:prev,ly,none'], 'branch_id' => ['nullable', 'integer'], 'format' => ['nullable', 'in:json,csv,xlsx'],
        ]);
        $hotel = $user->hotel;
        $today = HotelClock::now($hotel)->toDateString();
        $from = $data['from'] ?? Carbon::parse($today)->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today;
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) {
            abort(422, 'Choose at most 400 days.');
        }
        $branchIds = $this->branchIds($request);
        $m = Metrics::for($hotel, $branchIds);
        $compare = Metrics::compareRange($from, $to, $data['compare'] ?? 'prev');

        $report = match ($key) {
            'overview' => ['kpis' => $m->kpis($from, $to), 'compare' => $compare ? $m->kpis(...$compare) : null],
            'performance' => $this->performance($m, $from, $to, $compare),
            'payments' => $m->paymentsSummary($from, $to),
            'outstanding' => ['rows' => $m->outstanding()],
            'tax' => ['rows' => $m->tax($from, $to)],
            'guests' => $m->guests($from, $to),
            'cancellations' => $m->cancellations($from, $to),
            'branches' => ['rows' => Branch::whereIn('id', $branchIds)->orderBy('name')->get()->map(fn (Branch $b) => ['branch' => $b->name] + Metrics::for($hotel, [$b->id])->kpis($from, $to))->all()],
            'housekeeping' => ['rows' => $m->housekeeping($from, $to)],
        };
        $report += ['from' => $from, 'to' => $to, 'compare_range' => $compare, 'currency' => $hotel->default_currency ?? 'ETB'];

        $format = $data['format'] ?? 'json';
        if ($format !== 'json') {
            abort_unless($user->hasPermission('reports.export'), 403, 'You may not export reports.');

            return $this->export($key, $report, $format, $hotel->name);
        }

        return response()->json(['data' => $report]);
    }

    /** The bookings or payments behind a number (reports.md feature 3). */
    public function drilldown(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('reports.revenue') || $user->hasPermission('reports.occupancy') || $user->hasPermission('reports.payments'), 403);
        $data = $request->validate(['metric' => ['required', 'in:room_nights,payments'], 'date' => ['required', 'date'], 'branch_id' => ['nullable', 'integer']]);
        $hotel = $user->hotel;
        $branchIds = $this->branchIds($request);

        if ($data['metric'] === 'payments') {
            $start = Carbon::parse($data['date'], HotelClock::zone($hotel))->startOfDay()->utc();
            $rows = Payment::with(['method:id,name', 'reservation:id,code,guest_id', 'reservation.guest:id,first_name,last_name', 'receiver:id,name'])
                ->whereIn('branch_id', $branchIds)->whereBetween('paid_at', [$start, $start->copy()->addDay()])->orderBy('paid_at')->get()
                ->map(fn (Payment $p) => ['id' => $p->id, 'type' => $p->type, 'amount' => $p->amount_in_base, 'method' => $p->method?->name, 'by' => $p->receiver?->name,
                    'reservation_id' => $p->reservation_id, 'code' => $p->reservation?->code, 'guest' => $p->reservation?->guest?->full_name, 'time' => HotelClock::local($p->paid_at, $hotel)->format('H:i')]);

            return response()->json(['data' => $rows]);
        }

        $rows = ReservationRoomRate::query()->join('reservation_rooms as rr', 'rr.id', '=', 'reservation_room_rates.reservation_room_id')
            ->join('reservations as res', 'res.id', '=', 'rr.reservation_id')->leftJoin('guests as g', 'g.id', '=', 'res.guest_id')
            ->leftJoin('rooms as room', 'room.id', '=', 'rr.room_id')->leftJoin('room_types as rt', 'rt.id', '=', 'rr.room_type_id')
            ->where('res.hotel_id', $hotel->id)->whereIn('res.branch_id', $branchIds)->whereIn('rr.status', ['checked_in', 'checked_out'])
            ->where('reservation_room_rates.date', $data['date'])->where('reservation_room_rates.label', 'Night')
            ->get(['res.id as reservation_id', 'res.code', 'g.first_name', 'g.last_name', 'room.number', 'rt.name as room_type', 'reservation_room_rates.amount', 'reservation_room_rates.rule_name'])
            ->map(fn ($r) => ['reservation_id' => $r->reservation_id, 'code' => $r->code, 'guest' => trim($r->first_name.' '.$r->last_name), 'room' => $r->number, 'room_type' => $r->room_type, 'amount' => $r->amount, 'rule' => $r->rule_name]);

        return response()->json(['data' => $rows]);
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function performance(Metrics $m, string $from, string $to, ?array $compare): array
    {
        return [
            'kpis' => $m->kpis($from, $to),
            'compare' => $compare ? $m->kpis(...$compare) : null,
            'daily' => $m->daily($from, $to),
            'room_types' => $m->byRoomType($from, $to),
            'sources' => $m->bySource($from, $to),
        ];
    }

    /** Share of rooms already booked for a day (on the books, any active booking). */
    private function onTheBooks($hotel, array $branchIds, string $date, int $sellable): float
    {
        if ($sellable === 0) {
            return 0.0;
        }
        $n = ReservationRoomRate::query()->join('reservation_rooms as rr', 'rr.id', '=', 'reservation_room_rates.reservation_room_id')
            ->join('reservations as res', 'res.id', '=', 'rr.reservation_id')->where('res.hotel_id', $hotel->id)->whereIn('res.branch_id', $branchIds)
            ->whereIn('rr.status', ['pending', 'confirmed', 'checked_in', 'checked_out'])->where('reservation_room_rates.date', $date)->where('reservation_room_rates.label', 'Night')->count();

        return round($n / $sellable * 100, 1);
    }

    /** @return list<int> */
    private function branchIds(Request $request): array
    {
        $ids = $request->user()->accessibleBranches()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $b = $request->integer('branch_id');

        return $b && in_array($b, $ids, true) ? [$b] : $ids;
    }

    /** @param  array<string, mixed>  $report */
    private function export(string $key, array $report, string $format, string $hotelName): Response
    {
        [$headers, $rows] = match ($key) {
            'overview' => [['Measure', 'Value', 'Compared'], collect($report['kpis'])->map(fn ($v, $k) => [$k, $v, $report['compare'][$k] ?? ''])->values()->all()],
            'performance' => [['Date', 'Room nights', 'Occupancy %', 'Room revenue', 'ADR', 'Revenue', 'Payments'], array_map(fn ($d) => array_values($d), $report['daily'])],
            'payments' => [['Method', 'Type', 'Currency', 'Count', 'Amount', 'In main currency'], array_map(fn ($r) => array_values($r), $report['by_method'])],
            'outstanding' => [['Invoice', 'Guest', 'Phone', 'Booking', 'Issued', 'Age (days)', 'Total', 'Balance'], array_map(fn ($r) => [$r['number'], $r['guest'], $r['phone'], $r['reservation'], $r['issued_at'], $r['age_days'], $r['total'], $r['balance']], $report['rows'])],
            'tax' => [['Tax', 'Rate %', 'Included in price', 'Base', 'Tax', 'Invoices'], array_map(fn ($r) => [$r['name'], $r['rate'], $r['is_inclusive'] ? 'yes' : 'no', $r['base'], $r['tax'], $r['invoices']], $report['rows'])],
            'guests' => [['Nationality', 'Guests'], array_map(fn ($r) => array_values($r), $report['nationalities'])],
            'cancellations' => [['Booking', 'Guest', 'Status', 'Reason', 'Check-in', 'Cancelled on', 'Booking value', 'Fee'], array_map(fn ($r) => [$r['code'], $r['guest'], $r['status'], $r['reason'], $r['check_in'], $r['cancelled_on'], $r['total'], $r['fee']], $report['rows'])],
            'branches' => [['Branch', 'Occupancy %', 'Room nights', 'ADR', 'RevPAR', 'Revenue', 'Payments'], array_map(fn ($r) => [$r['branch'], $r['occupancy'], $r['room_nights'], $r['adr'], $r['revpar'], $r['revenue'], $r['payments']], $report['rows'])],
            'housekeeping' => [['Person', 'Task', 'Tasks done', 'Minutes per task'], array_map(fn ($r) => [$r['name'], $r['type'], $r['tasks'], $r['minutes']], $report['rows'])],
        };
        $name = "{$key}-{$report['from']}-{$report['to']}";
        $rows = array_map(fn ($r) => array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : $v, $r), $rows);

        return $format === 'csv'
            ? response(Spreadsheet::csv($headers, $rows), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""])
            : response(Spreadsheet::xlsx(mb_substr($hotelName, 0, 31), $headers, $rows), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\""]);
    }
}

<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hotel KPIs from the bookings and bills themselves (reports.md feature 1):
 * occupancy, ADR, RevPAR, revenue, room nights, length of stay, cancellation rate, lead time.
 * A room night counts once the guest has checked in (checked in or out). Dates are the hotel's own dates.
 */
class Metrics
{
    private int $offset;

    /** @param  list<int>  $branchIds */
    public function __construct(private Hotel $hotel, private array $branchIds)
    {
        // Minutes from UTC (Ethiopia has no daylight saving time), for grouping times by local date.
        $this->offset = (int) (HotelClock::now($hotel)->utcOffset());
    }

    public static function for(Hotel $hotel, array $branchIds): self
    {
        return new self($hotel, array_values(array_map('intval', $branchIds)));
    }

    /** "2026-11-12" local → UTC start of that day. */
    private function utc(string $date): Carbon
    {
        return Carbon::parse($date, HotelClock::zone($this->hotel))->startOfDay()->utc();
    }

    private function localDate(string $column): string
    {
        return "DATE(DATE_ADD({$column}, INTERVAL {$this->offset} MINUTE))";
    }

    /** @return list<string> */
    public static function days(string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** Rooms that can be sold (out of order rooms are not counted). */
    public function roomCount(): int
    {
        return DB::table('rooms')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)
            ->whereNull('deleted_at')->where('status', '!=', 'out_of_order')->count();
    }

    // ------------------------------------------------------------------ building blocks

    private function nights(): Builder
    {
        return DB::table('reservation_room_rates as r')
            ->join('reservation_rooms as rr', 'rr.id', '=', 'r.reservation_room_id')
            ->join('reservations as res', 'res.id', '=', 'rr.reservation_id')
            ->where('res.hotel_id', $this->hotel->id)->whereIn('res.branch_id', $this->branchIds)
            ->where('r.label', 'Night');
    }

    private function items(): Builder
    {
        return DB::table('invoice_items as it')
            ->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')
            ->where('inv.hotel_id', $this->hotel->id)->whereIn('inv.branch_id', $this->branchIds)
            ->where('inv.status', '!=', 'void');
    }

    private function reservations(): Builder
    {
        return DB::table('reservations')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)->whereNull('deleted_at');
    }

    private function payments(): Builder
    {
        return DB::table('payments')->where('payments.hotel_id', $this->hotel->id)->whereIn('payments.branch_id', $this->branchIds);
    }

    // ------------------------------------------------------------------ KPIs

    /** @return array<string, mixed> */
    public function kpis(string $from, string $to): array
    {
        $days = count(self::days($from, $to));
        $available = $this->roomCount() * $days;
        $sold = (int) $this->nights()->whereIn('rr.status', ['checked_in', 'checked_out'])->whereBetween('r.date', [$from, $to])->count();

        $byType = $this->items()->whereBetween('it.service_date', [$from, $to])->selectRaw('it.type, SUM(it.amount) as total')->groupBy('it.type')->pluck('total', 'type');
        $room = Money::round((string) ($byType['room'] ?? '0'));
        $extras = Money::round((string) ($byType['extra'] ?? '0'));
        $fees = Money::sum([(string) ($byType['early_check_in'] ?? '0'), (string) ($byType['late_check_out'] ?? '0'), (string) ($byType['early_check_out'] ?? '0')]);
        $discounts = Money::sub('0', (string) ($byType['discount'] ?? '0'));
        $cancelFees = Money::round((string) $this->reservations()->whereIn('status', ['cancelled', 'no_show'])
            ->whereBetween('cancelled_at', [$this->utc($from), $this->utc($to)->addDay()])->sum('cancellation_fee') ?: '0');
        $revenue = Money::sub(Money::add(Money::add(Money::add($room, $extras), $fees), $cancelFees), $discounts);

        $tax = Money::round((string) DB::table('invoice_item_taxes as t')->join('invoice_items as it', 'it.id', '=', 't.invoice_item_id')
            ->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')->where('inv.hotel_id', $this->hotel->id)->whereIn('inv.branch_id', $this->branchIds)
            ->where('inv.status', '!=', 'void')->where('t.is_inclusive', false)->whereBetween('it.service_date', [$from, $to])->sum('t.amount') ?: '0');

        $paid = $this->payments()->whereBetween('paid_at', [$this->utc($from), $this->utc($to)->addDay()])
            ->selectRaw("SUM(CASE WHEN type = 'refund' THEN -amount_in_base ELSE amount_in_base END) as t")->value('t');

        $created = $this->reservations()->whereBetween('created_at', [$this->utc($from), $this->utc($to)->addDay()]);
        $createdCount = (clone $created)->count();
        $lost = (clone $created)->whereIn('status', ['cancelled', 'no_show'])->count();
        $lead = (clone $created)->where('source', '!=', 'walk_in')->selectRaw('AVG(GREATEST(DATEDIFF(check_in_at, created_at), 0)) as d')->value('d');
        $alos = $this->reservations()->where('status', 'checked_out')->where('stay_type', 'nightly')
            ->whereBetween('check_out_at', [$this->utc($from), $this->utc($to)->addDay()])
            ->selectRaw('AVG(DATEDIFF('.$this->localDate('check_out_at').', '.$this->localDate('check_in_at').')) as d')->value('d');

        return [
            'rooms' => $this->roomCount(),
            'available_nights' => $available,
            'room_nights' => $sold,
            'occupancy' => $available > 0 ? round($sold / $available * 100, 1) : 0.0,
            'room_revenue' => $room,
            'adr' => $sold > 0 ? Money::round(bcdiv($room, (string) $sold, 4)) : '0.00',
            'revpar' => $available > 0 ? Money::round(bcdiv($room, (string) $available, 4)) : '0.00',
            'extras' => $extras,
            'fees' => Money::round($fees),
            'discounts' => $discounts,
            'cancellation_fees' => $cancelFees,
            'revenue' => $revenue,
            'tax' => $tax,
            'payments' => Money::round((string) ($paid ?? '0')),
            'bookings' => $createdCount,
            'cancellation_rate' => $createdCount > 0 ? round($lost / $createdCount * 100, 1) : 0.0,
            'lead_time' => $lead !== null ? round((float) $lead, 1) : 0.0,
            'average_stay' => $alos !== null ? round((float) $alos, 1) : 0.0,
        ];
    }

    /** The period to compare with: the one just before (prev) or the same dates last year (ly). */
    public static function compareRange(string $from, string $to, string $mode): ?array
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);
        if ($mode === 'ly') {
            return [$f->copy()->subYear()->toDateString(), $t->copy()->subYear()->toDateString()];
        }
        if ($mode === 'prev') {
            $len = $f->diffInDays($t) + 1;

            return [$f->copy()->subDays($len)->toDateString(), $f->copy()->subDay()->toDateString()];
        }

        return null;
    }

    /**
     * Day by day: room nights, occupancy, room revenue, revenue, payments.
     *
     * @return list<array<string, mixed>>
     */
    public function daily(string $from, string $to): array
    {
        $rooms = $this->roomCount();
        $nights = $this->nights()->whereIn('rr.status', ['checked_in', 'checked_out'])->whereBetween('r.date', [$from, $to])
            ->selectRaw('r.date as d, COUNT(*) as n')->groupBy('r.date')->pluck('n', 'd');
        $items = $this->items()->whereBetween('it.service_date', [$from, $to])
            ->selectRaw("it.service_date as d, SUM(CASE WHEN it.type = 'room' THEN it.amount ELSE 0 END) as room, SUM(it.amount) as total")
            ->groupBy('it.service_date')->get()->keyBy('d');
        $pay = $this->payments()->whereBetween('paid_at', [$this->utc($from), $this->utc($to)->addDay()])
            ->selectRaw($this->localDate('paid_at')." as d, SUM(CASE WHEN type = 'refund' THEN -amount_in_base ELSE amount_in_base END) as t")
            ->groupByRaw($this->localDate('paid_at'))->pluck('t', 'd');

        return array_map(function (string $d) use ($rooms, $nights, $items, $pay) {
            $n = (int) ($nights[$d] ?? 0);
            $room = Money::round((string) ($items[$d]->room ?? '0'));

            return [
                'date' => $d,
                'room_nights' => $n,
                'occupancy' => $rooms > 0 ? round($n / $rooms * 100, 1) : 0.0,
                'room_revenue' => $room,
                'adr' => $n > 0 ? Money::round(bcdiv($room, (string) $n, 4)) : '0.00',
                'revenue' => Money::round((string) ($items[$d]->total ?? '0')),
                'payments' => Money::round((string) ($pay[$d] ?? '0')),
            ];
        }, self::days($from, $to));
    }

    /** @return list<array<string, mixed>> room nights and room revenue per room type */
    public function byRoomType(string $from, string $to): array
    {
        $nights = $this->nights()->whereIn('rr.status', ['checked_in', 'checked_out'])->whereBetween('r.date', [$from, $to])
            ->selectRaw('rr.room_type_id as t, COUNT(*) as n')->groupBy('rr.room_type_id')->pluck('n', 't');
        $revenue = $this->items()->join('reservation_rooms as rr', 'rr.id', '=', 'it.reservation_room_id')->where('it.type', 'room')
            ->whereBetween('it.service_date', [$from, $to])->selectRaw('rr.room_type_id as t, SUM(it.amount) as s')->groupBy('rr.room_type_id')->pluck('s', 't');
        $types = DB::table('room_types')->where('hotel_id', $this->hotel->id)->whereIn('branch_id', $this->branchIds)->get(['id', 'name', 'branch_id']);
        $rooms = DB::table('rooms')->where('hotel_id', $this->hotel->id)->whereNull('deleted_at')->where('status', '!=', 'out_of_order')
            ->selectRaw('room_type_id, COUNT(*) as c')->groupBy('room_type_id')->pluck('c', 'room_type_id');
        $days = count(self::days($from, $to));

        return $types->map(fn ($t) => [
            'room_type_id' => $t->id, 'name' => $t->name,
            'room_nights' => (int) ($nights[$t->id] ?? 0),
            'occupancy' => ($rooms[$t->id] ?? 0) > 0 ? round(((int) ($nights[$t->id] ?? 0)) / ($rooms[$t->id] * $days) * 100, 1) : 0.0,
            'room_revenue' => Money::round((string) ($revenue[$t->id] ?? '0')),
        ])->sortByDesc('room_revenue')->values()->all();
    }

    /** @return list<array<string, mixed>> bookings and value per source (phone, walk-in, agent…) */
    public function bySource(string $from, string $to): array
    {
        return $this->reservations()->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('check_in_at', '<', $this->utc($to)->addDay())->where('check_out_at', '>', $this->utc($from))
            ->selectRaw('source, COUNT(*) as bookings, SUM(total) as value')->groupBy('source')->orderByDesc('bookings')->get()
            ->map(fn ($r) => ['source' => $r->source, 'bookings' => (int) $r->bookings, 'value' => Money::round((string) $r->value)])->all();
    }

    /** @return array{by_method: list<array<string, mixed>>, by_staff: list<array<string, mixed>>, total: string} */
    public function paymentsSummary(string $from, string $to): array
    {
        $base = fn () => $this->payments()->whereBetween('payments.paid_at', [$this->utc($from), $this->utc($to)->addDay()]);
        $signed = "SUM(CASE WHEN payments.type = 'refund' THEN -payments.amount ELSE payments.amount END)";
        $signedBase = "SUM(CASE WHEN payments.type = 'refund' THEN -payments.amount_in_base ELSE payments.amount_in_base END)";

        $byMethod = $base()->join('payment_methods as m', 'm.id', '=', 'payments.payment_method_id')
            ->selectRaw("m.name as method, m.type as method_type, payments.currency, COUNT(*) as count, {$signed} as amount, {$signedBase} as amount_in_base")
            ->groupBy('m.name', 'm.type', 'payments.currency')->orderByDesc('amount_in_base')->get()
            ->map(fn ($r) => ['method' => $r->method, 'method_type' => $r->method_type, 'currency' => $r->currency, 'count' => (int) $r->count, 'amount' => Money::round((string) $r->amount), 'amount_in_base' => Money::round((string) $r->amount_in_base)])->all();
        $byStaff = $base()->leftJoin('users as u', 'u.id', '=', 'payments.received_by')
            ->selectRaw("u.name as name, COUNT(*) as count, {$signedBase} as amount")->groupBy('u.name')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['name' => $r->name ?? '—', 'count' => (int) $r->count, 'amount' => Money::round((string) $r->amount)])->all();

        return ['by_method' => $byMethod, 'by_staff' => $byStaff, 'total' => Money::sum(array_column($byMethod, 'amount_in_base'))];
    }

    /** @return list<array<string, mixed>> unpaid bills, oldest first */
    public function outstanding(): array
    {
        return DB::table('invoices as inv')->leftJoin('guests as g', 'g.id', '=', 'inv.guest_id')->leftJoin('reservations as res', 'res.id', '=', 'inv.reservation_id')
            ->where('inv.hotel_id', $this->hotel->id)->whereIn('inv.branch_id', $this->branchIds)->where('inv.status', '!=', 'void')->where('inv.balance', '>', 0)
            ->orderBy('inv.issued_at')->limit(500)
            ->get(['inv.id', 'inv.number', 'inv.status', 'inv.total', 'inv.balance', 'inv.issued_at', 'inv.currency', 'g.first_name', 'g.last_name', 'g.phone', 'res.code', 'res.id as reservation_id', 'res.status as stay_status'])
            ->map(fn ($r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'guest' => trim($r->first_name.' '.$r->last_name), 'phone' => $r->phone,
                'reservation' => $r->code, 'reservation_id' => $r->reservation_id, 'stay_status' => $r->stay_status,
                'total' => Money::round((string) $r->total), 'balance' => Money::round((string) $r->balance), 'currency' => $r->currency,
                'issued_at' => substr((string) $r->issued_at, 0, 10), 'age_days' => (int) Carbon::parse($r->issued_at)->diffInDays(now()),
            ])->all();
    }

    /** @return list<array<string, mixed>> tax per name and rate, with its base (for filing) */
    public function tax(string $from, string $to): array
    {
        return DB::table('invoice_item_taxes as t')->join('invoice_items as it', 'it.id', '=', 't.invoice_item_id')->join('invoices as inv', 'inv.id', '=', 'it.invoice_id')
            ->where('inv.hotel_id', $this->hotel->id)->whereIn('inv.branch_id', $this->branchIds)->where('inv.status', '!=', 'void')
            ->whereBetween('it.service_date', [$from, $to])
            ->selectRaw('t.name, t.rate, t.is_inclusive, SUM(it.amount) as base, SUM(t.amount) as tax, COUNT(DISTINCT inv.id) as invoices')
            ->groupBy('t.name', 't.rate', 't.is_inclusive')->orderBy('t.name')->get()
            ->map(fn ($r) => ['name' => $r->name, 'rate' => (string) $r->rate, 'is_inclusive' => (bool) $r->is_inclusive, 'base' => Money::round((string) $r->base), 'tax' => Money::round((string) $r->tax), 'invoices' => (int) $r->invoices])->all();
    }

    /** @return array<string, mixed> nationalities, repeat and top guests */
    public function guests(string $from, string $to): array
    {
        $stays = $this->reservations()->whereIn('status', ['checked_in', 'checked_out'])
            ->where('check_in_at', '<', $this->utc($to)->addDay())->where('check_out_at', '>', $this->utc($from));
        $guestIds = (clone $stays)->distinct()->pluck('guest_id');
        $nationalities = DB::table('guests')->whereIn('id', $guestIds)->selectRaw("COALESCE(NULLIF(nationality, ''), '—') as n, COUNT(*) as c")->groupBy('n')->orderByDesc('c')->get()
            ->map(fn ($r) => ['nationality' => $r->n, 'guests' => (int) $r->c])->all();
        $repeat = DB::table('reservations')->where('hotel_id', $this->hotel->id)->whereIn('guest_id', $guestIds)->whereIn('status', ['checked_in', 'checked_out'])
            ->selectRaw('guest_id, COUNT(*) as c')->groupBy('guest_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $top = DB::table('invoices as inv')->join('guests as g', 'g.id', '=', 'inv.guest_id')->where('inv.hotel_id', $this->hotel->id)->whereIn('inv.branch_id', $this->branchIds)
            ->where('inv.status', '!=', 'void')->whereBetween('inv.issued_at', [$this->utc($from), $this->utc($to)->addDay()])
            ->selectRaw('g.id, g.first_name, g.last_name, COUNT(inv.id) as bills, SUM(inv.total) as spent')->groupBy('g.id', 'g.first_name', 'g.last_name')->orderByDesc('spent')->limit(10)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => trim($r->first_name.' '.$r->last_name), 'bills' => (int) $r->bills, 'spent' => Money::round((string) $r->spent)])->all();

        return ['guests' => $guestIds->count(), 'repeat' => $repeat, 'nationalities' => $nationalities, 'top' => $top, 'sources' => $this->bySource($from, $to)];
    }

    /** @return array<string, mixed> cancellations and no-shows in the period (by the day they were cancelled) */
    public function cancellations(string $from, string $to): array
    {
        $rows = DB::table('reservations as r')->leftJoin('guests as g', 'g.id', '=', 'r.guest_id')
            ->where('r.hotel_id', $this->hotel->id)->whereIn('r.branch_id', $this->branchIds)->whereIn('r.status', ['cancelled', 'no_show'])
            ->whereBetween('r.cancelled_at', [$this->utc($from), $this->utc($to)->addDay()])->orderByDesc('r.cancelled_at')->limit(500)
            ->get(['r.id', 'r.code', 'r.status', 'r.cancel_reason', 'r.cancellation_fee', 'r.total', 'r.cancelled_at', 'r.check_in_at', 'g.first_name', 'g.last_name']);

        return [
            'cancelled' => $rows->where('status', 'cancelled')->where('cancel_reason', '!=', 'expired')->count(),
            'expired' => $rows->where('cancel_reason', 'expired')->count(),
            'no_show' => $rows->where('status', 'no_show')->count(),
            'fees' => Money::sum($rows->pluck('cancellation_fee')->map(fn ($v) => (string) ($v ?? '0'))),
            'lost_value' => Money::sum($rows->pluck('total')->map(fn ($v) => (string) $v)),
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'code' => $r->code, 'status' => $r->status, 'reason' => $r->cancel_reason, 'guest' => trim($r->first_name.' '.$r->last_name),
                'fee' => Money::round((string) ($r->cancellation_fee ?? '0')), 'total' => Money::round((string) $r->total),
                'cancelled_on' => HotelClock::local(Carbon::parse($r->cancelled_at, 'UTC'), $this->hotel)->toDateString(),
                'check_in' => HotelClock::local(Carbon::parse($r->check_in_at, 'UTC'), $this->hotel)->toDateString(),
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> rooms cleaned and minutes per room, per person */
    public function housekeeping(string $from, string $to): array
    {
        return DB::table('housekeeping_tasks as t')->leftJoin('users as u', 'u.id', '=', 't.completed_by')
            ->where('t.hotel_id', $this->hotel->id)->whereIn('t.branch_id', $this->branchIds)->where('t.status', 'done')
            ->whereBetween('t.completed_at', [$this->utc($from), $this->utc($to)->addDay()])
            ->selectRaw('u.id, u.name, t.type, COUNT(*) as tasks, AVG(TIMESTAMPDIFF(MINUTE, t.started_at, t.completed_at)) as minutes')
            ->groupBy('u.id', 'u.name', 't.type')->orderByDesc('tasks')->get()
            ->map(fn ($r) => ['user_id' => $r->id, 'name' => $r->name ?? '—', 'type' => $r->type, 'tasks' => (int) $r->tasks, 'minutes' => $r->minutes !== null ? (int) round((float) $r->minutes) : null])->all();
    }
}

<?php

namespace App\Services\Reports;

use App\Models\Hotel;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Custom report builder (reports.md feature 16): pick measures and one "group by".
 * Each measure comes from its own records (nights, bookings or payments); a measure that
 * cannot be grouped that way shows "—".
 */
class CustomReportEngine
{
    public const MEASURES = ['room_nights', 'room_revenue', 'bookings', 'booking_value', 'cancellations', 'payments'];

    public const GROUPS = ['day', 'week', 'month', 'branch', 'room_type', 'source', 'payment_method', 'staff'];

    /** Which groupings each measure supports. */
    private const SUPPORTS = [
        'room_nights' => ['day', 'week', 'month', 'branch', 'room_type', 'source', 'staff'],
        'room_revenue' => ['day', 'week', 'month', 'branch', 'room_type', 'source', 'staff'],
        'bookings' => ['day', 'week', 'month', 'branch', 'source', 'staff'],
        'booking_value' => ['day', 'week', 'month', 'branch', 'source', 'staff'],
        'cancellations' => ['day', 'week', 'month', 'branch', 'source', 'staff'],
        'payments' => ['day', 'week', 'month', 'branch', 'payment_method', 'staff'],
    ];

    private int $offset;

    /** @param  list<int>  $branchIds */
    public function __construct(private Hotel $hotel, private array $branchIds)
    {
        $this->offset = (int) HotelClock::now($hotel)->utcOffset();
    }

    public static function supports(string $measure, string $group): bool
    {
        return in_array($group, self::SUPPORTS[$measure] ?? [], true);
    }

    /**
     * @param  list<string>  $measures
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function run(array $measures, string $group, string $from, string $to): array
    {
        $byMeasure = [];
        foreach ($measures as $m) {
            $byMeasure[$m] = self::supports($m, $group) ? $this->measure($m, $group, $from, $to) : null;
        }
        $keys = collect($byMeasure)->filter()->flatMap(fn ($values) => array_keys($values))->unique()->sort()->values();
        $labels = $this->labels($group, $keys->all());

        $rows = $keys->map(function ($k) use ($byMeasure, $labels) {
            $row = ['key' => (string) $k, 'label' => $labels[$k] ?? (string) $k];
            foreach ($byMeasure as $m => $values) {
                $row[$m] = $values === null ? null : ($values[$k] ?? (in_array($m, ['room_revenue', 'booking_value', 'payments'], true) ? '0.00' : 0));
            }

            return $row;
        });
        if (! in_array($group, ['day', 'week', 'month'], true)) {
            $first = $measures[0] ?? null;
            $rows = $rows->sortByDesc(fn ($r) => $first ? (float) $r[$first] : 0);
        }

        return ['columns' => $measures, 'rows' => $rows->values()->all()];
    }

    private function local(string $column): string
    {
        return "DATE_ADD({$column}, INTERVAL {$this->offset} MINUTE)";
    }

    private function key(string $group, string $dateExpr, array $columns): string
    {
        return match ($group) {
            'day' => "DATE({$dateExpr})",
            'week' => "DATE_FORMAT(DATE_SUB(DATE({$dateExpr}), INTERVAL WEEKDAY({$dateExpr}) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT({$dateExpr}, '%Y-%m')",
            default => $columns[$group],
        };
    }

    /** @return array<string, int|string> group key → value */
    private function measure(string $m, string $group, string $from, string $to): array
    {
        $start = Carbon::parse($from, HotelClock::zone($this->hotel))->startOfDay()->utc();
        $end = Carbon::parse($to, HotelClock::zone($this->hotel))->endOfDay()->utc();

        if (in_array($m, ['room_nights', 'room_revenue'], true)) {
            $key = $this->key($group, 'r.date', ['branch' => 'res.branch_id', 'room_type' => 'rr.room_type_id', 'source' => 'res.source', 'staff' => 'res.created_by']);
            $agg = $m === 'room_nights' ? 'COUNT(*)' : 'SUM(r.amount)';
            $q = DB::table('reservation_room_rates as r')->join('reservation_rooms as rr', 'rr.id', '=', 'r.reservation_room_id')->join('reservations as res', 'res.id', '=', 'rr.reservation_id')
                ->where('res.hotel_id', $this->hotel->id)->whereIn('res.branch_id', $this->branchIds)->whereIn('rr.status', ['checked_in', 'checked_out'])
                ->where('r.label', 'Night')->whereBetween('r.date', [$from, $to]);
        } elseif ($m === 'payments') {
            $key = $this->key($group, $this->local('p.paid_at'), ['branch' => 'p.branch_id', 'payment_method' => 'p.payment_method_id', 'staff' => 'p.received_by']);
            $agg = "SUM(CASE WHEN p.type = 'refund' THEN -p.amount_in_base ELSE p.amount_in_base END)";
            $q = DB::table('payments as p')->where('p.hotel_id', $this->hotel->id)->whereIn('p.branch_id', $this->branchIds)->whereBetween('p.paid_at', [$start, $end]);
        } else {
            $key = $this->key($group, $this->local('res.created_at'), ['branch' => 'res.branch_id', 'source' => 'res.source', 'staff' => 'res.created_by']);
            $agg = match ($m) {
                'bookings' => 'COUNT(*)',
                'booking_value' => "SUM(CASE WHEN res.status IN ('cancelled', 'no_show') THEN 0 ELSE res.total END)",
                default => "SUM(CASE WHEN res.status IN ('cancelled', 'no_show') THEN 1 ELSE 0 END)",
            };
            $q = DB::table('reservations as res')->where('res.hotel_id', $this->hotel->id)->whereIn('res.branch_id', $this->branchIds)->whereNull('res.deleted_at')->whereBetween('res.created_at', [$start, $end]);
        }

        $money = in_array($m, ['room_revenue', 'booking_value', 'payments'], true);

        return $q->selectRaw("{$key} as k, {$agg} as v")->groupByRaw($key)->pluck('v', 'k')
            ->mapWithKeys(fn ($v, $k) => [(string) ($k ?? '—') => $money ? Money::round((string) ($v ?? '0')) : (int) $v])->all();
    }

    /** @return array<string, string> */
    private function labels(string $group, array $keys): array
    {
        $ids = array_filter($keys, fn ($k) => is_numeric($k));
        $names = match ($group) {
            'branch' => DB::table('branches')->where('hotel_id', $this->hotel->id)->whereIn('id', $ids)->pluck('name', 'id'),
            'room_type' => DB::table('room_types')->where('hotel_id', $this->hotel->id)->whereIn('id', $ids)->pluck('name', 'id'),
            'payment_method' => DB::table('payment_methods')->where('hotel_id', $this->hotel->id)->whereIn('id', $ids)->pluck('name', 'id'),
            'staff' => DB::table('users')->where('hotel_id', $this->hotel->id)->whereIn('id', $ids)->pluck('name', 'id'),
            default => collect(),
        };

        return collect($keys)->mapWithKeys(fn ($k) => [$k => (string) ($names[$k] ?? $k)])->all();
    }
}

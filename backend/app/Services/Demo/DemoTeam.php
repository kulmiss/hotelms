<?php

namespace App\Services\Demo;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The demo team's work around the bookings: a shift rota (two weeks back, two weeks ahead),
 * attendance for the past two weeks, and cashier shifts holding the cash they took.
 * Written in bulk like DemoHistory, so the reset stays fast.
 */
class DemoTeam
{
    private const SHIFTS = [
        'Morning' => ['07:00:00', '15:00:00'],
        'Evening' => ['15:00:00', '23:00:00'],
        'Night' => ['23:00:00', '07:00:00'],
    ];

    /** Who works which shift (the rest are off on their day off). */
    private const ROTA = [
        'reception' => ['Morning', 'Morning', 'Evening', 'Evening', 'Morning', 'Morning'],
        'housekeeping' => ['Morning'],
        'manager' => ['Morning'],
        'admin' => ['Morning'],
        'accountant' => ['Morning'],
    ];

    private Hotel $hotel;

    private string $tz;

    /** @param list<Branch> $branches */
    public function build(Hotel $hotel, array $branches, Carbon $today, string $tz): void
    {
        mt_srand(2027);
        $this->hotel = $hotel;
        $this->tz = $tz;
        $people = User::where('hotel_id', $hotel->id)->get()->keyBy(fn (User $u) => explode('@', substr($u->email, 5))[0]);
        $bole = $branches[0]->id;
        $now = now()->toDateTimeString();

        $shiftIds = [];
        foreach (self::SHIFTS as $name => [$start, $end]) {
            $shiftIds[$name] = DB::table('shifts')->insertGetId(['hotel_id' => $hotel->id, 'branch_id' => $bole, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'created_at' => $now, 'updated_at' => $now]);
        }

        $assignments = [];
        for ($i = -14; $i <= 14; $i++) {
            $day = $today->copy()->addDays($i);
            foreach (self::ROTA as $key => $pattern) {
                $user = $people[$key] ?? null;
                // One day off a week (office staff: Sunday).
                if (! $user || ($key === 'reception' ? $day->dayOfWeekIso === 3 : $day->dayOfWeekIso === 7)) {
                    continue;
                }
                $shift = $pattern[($day->dayOfYear) % count($pattern)];
                $assignments[] = ['hotel_id' => $hotel->id, 'branch_id' => $bole, 'user_id' => $user->id, 'shift_id' => $shiftIds[$shift], 'date' => $day->toDateString(), 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('shift_assignments')->insert($assignments);

        $this->attendance($bole, $today, $now);
        $this->cashierShifts($people, $today, $now);
    }

    /** Clock-ins for every planned shift that has started: mostly on time, sometimes late, rarely absent. */
    private function attendance(int $branchId, Carbon $today, string $now): void
    {
        $rows = [];
        $planned = DB::table('shift_assignments as a')->join('shifts as s', 's.id', '=', 'a.shift_id')
            ->where('a.hotel_id', $this->hotel->id)->where('a.date', '<=', $today->toDateString())
            ->get(['a.id', 'a.user_id', 'a.date', 's.start_time', 's.end_time']);
        foreach ($planned as $a) {
            $start = Carbon::parse($a->date.' '.$a->start_time, $this->tz);
            $end = Carbon::parse($a->date.' '.$a->end_time, $this->tz);
            if ($end->lte($start)) {
                $end->addDay();
            }
            if ($start->gt(now())) {
                continue; // not started yet
            }
            $roll = mt_rand(1, 100);
            $status = $roll <= 3 ? 'absent' : ($roll <= 5 ? 'leave' : ($roll <= 17 ? 'late' : 'present'));
            $in = in_array($status, ['absent', 'leave'], true) ? null : $start->copy()->addMinutes($status === 'late' ? mt_rand(16, 45) : mt_rand(-15, 5));
            $out = $in && $end->lt(now()) ? $end->copy()->addMinutes(mt_rand(-5, 25)) : null;
            $rows[] = [
                'hotel_id' => $this->hotel->id, 'branch_id' => $branchId, 'user_id' => $a->user_id, 'shift_assignment_id' => $a->id, 'date' => $a->date,
                'clock_in_at' => $in?->utc()->toDateTimeString(), 'clock_out_at' => $out?->utc()->toDateTimeString(),
                'method' => $in ? (mt_rand(0, 1) ? 'kiosk_pin' : 'self') : 'manual', 'recorded_by' => null, 'status' => $status,
                'notes' => $status === 'leave' ? 'Annual leave' : null, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('attendances')->insert($rows);
    }

    /**
     * One cashier shift per person per day for the last two weeks, holding the cash they took that day.
     * Earlier days are closed and approved (one came up a little short); today's shift is still open.
     *
     * @param  Collection<string, User>  $people
     */
    private function cashierShifts($people, Carbon $today, string $now): void
    {
        $from = $today->copy()->subDays(14);
        $cash = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.hotel_id', $this->hotel->id)->where('m.type', 'cash')->where('p.paid_at', '>=', $from->copy()->utc()->toDateTimeString())
            ->get(['p.id', 'p.received_by', 'p.branch_id', 'p.paid_at', 'p.amount', 'p.type']);
        $groups = [];
        foreach ($cash as $p) {
            $day = Carbon::parse($p->paid_at, 'UTC')->setTimezone($this->tz)->toDateString();
            $groups["{$p->received_by}|{$p->branch_id}|{$day}"][] = $p;
        }
        ksort($groups);
        $approver = $people['manager']->id ?? null;
        $admin = $people['admin']->id ?? null;
        $short = true;
        foreach ($groups as $key => $payments) {
            [$userId, $branchId, $day] = explode('|', $key);
            $isToday = $day === $today->toDateString();
            $opened = Carbon::parse($day.' 07:05', $this->tz);
            $taken = '0.00';
            foreach ($payments as $p) {
                $taken = $p->type === 'refund' ? Money::sub($taken, (string) $p->amount) : Money::add($taken, (string) $p->amount);
            }
            $expected = Money::add('2000.00', $taken);
            $difference = (! $isToday && $short) ? '-150.00' : '0.00';
            $short = $short && $isToday; // only the first closed shift is short
            $sessionId = DB::table('cashier_sessions')->insertGetId([
                'hotel_id' => $this->hotel->id, 'branch_id' => (int) $branchId, 'user_id' => (int) $userId,
                'opened_at' => $opened->copy()->utc()->toDateTimeString(),
                'closed_at' => $isToday ? null : Carbon::parse($day.' 22:50', $this->tz)->utc()->toDateTimeString(),
                'status' => $isToday ? 'open' : 'approved',
                'approved_by' => $isToday ? null : ((int) $userId === $approver ? $admin : $approver),
                'approved_at' => $isToday ? null : Carbon::parse($day.' 23:10', $this->tz)->utc()->toDateTimeString(),
                'notes' => $difference !== '0.00' ? 'Gave wrong change to a guest.' : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('cashier_session_balances')->insert([
                'cashier_session_id' => $sessionId, 'currency' => 'ETB', 'opening_amount' => '2000.00',
                'expected_amount' => $isToday ? null : $expected, 'counted_amount' => $isToday ? null : Money::add($expected, $difference),
                'difference' => $isToday ? null : $difference, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('payments')->whereIn('id', array_map(fn ($p) => $p->id, $payments))->update(['cashier_session_id' => $sessionId]);
        }
    }
}

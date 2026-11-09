<?php

namespace App\Services\Smart;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Booking\Availability;
use App\Services\Modules\Cashier;
use App\Services\Reports\Metrics;
use App\Services\Switches;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Smart hints (smart-features.md): plain statistics on the hotel's own data, written as clear sentences.
 * Each hint has a "why", can be dismissed, and never changes anything. Free to run; nothing leaves the server.
 *
 * A hint: ['key', 'text', 'why', 'tone' (info|ok|warn), 'link'?, 'data'?, 'explain'?]
 * 'explain' is the full answer to "Why?": ['facts' => [[label, value], …], 'how' => how it was worked out, 'do' => what you can do].
 */
class SmartHints
{
    public function __construct(private Switches $switches) {}

    public function on(?Hotel $hotel, string $key): bool
    {
        return $this->switches->on($hotel, 'feature.smart_hints') && $this->switches->on($hotel, "smart.{$key}");
    }

    /** Week-on-week revenue (or occupancy) insight for the dashboard; null when there is nothing to say. */
    private function insight(Hotel $hotel, User $user, array $branchIds, string $today): ?array
    {
        $m = Metrics::for($hotel, $branchIds);
        $from = Carbon::parse($today)->subDays(6)->toDateString();
        $now = $m->kpis($from, $today);
        $before = $m->kpis(Carbon::parse($today)->subDays(13)->toDateString(), Carbon::parse($today)->subDays(7)->toDateString());
        if ($user->hasPermission('dashboard.view_revenue') && Money::isPositive($now['revenue'])) {
            $change = self::pct((float) $now['revenue'], (float) $before['revenue']);
            if ($change === null) {
                return null;
            }
            $top = collect($m->byRoomType($from, $today))->first();
            $way = $change >= 0 ? 'above' : 'below';

            return [
                'text' => 'Revenue this week is '.abs($change)."% {$way} last week".($top && Money::isPositive($top['room_revenue']) ? ", mostly from {$top['name']} rooms." : '.'),
                'why' => 'This week '.self::money($hotel->default_currency, $now['revenue']).', the week before '.self::money($hotel->default_currency, $before['revenue']).'.',
                'tone' => $change >= 0 ? 'ok' : 'warn', 'link' => '/reports?report=performance',
                'explain' => self::explain([
                    ['Revenue, last 7 days', self::money($hotel->default_currency, $now['revenue'])],
                    ['Revenue, the 7 days before', self::money($hotel->default_currency, $before['revenue'])],
                    ['Change', ($change >= 0 ? '+' : '').$change.'%'],
                    ['Occupancy, last 7 days', $now['occupancy'].'% (was '.$before['occupancy'].'%)'],
                    ['Average night (ADR)', self::money($hotel->default_currency, $now['adr']).' (was '.self::money($hotel->default_currency, $before['adr']).')'],
                ] + ($top ? [5 => ['Room type with most revenue', $top['name'].' · '.self::money($hotel->default_currency, $top['room_revenue'])]] : []),
                    'Revenue of today and the 6 days before, compared with the 7 days before that. Revenue is room nights, extras and fees on the bills, after discounts, without tax.',
                    $change >= 0 ? 'Keep prices as they are; check the Forecast report for the coming weeks.' : 'Open the report to see which days and room types fell. A weekend price or a corporate offer can help fill rooms.'),
            ];
        }
        if ($now['room_nights'] === 0 && $before['room_nights'] === 0) {
            return null;
        }
        $diff = round($now['occupancy'] - $before['occupancy']);

        return [
            'text' => "Occupancy this week is {$now['occupancy']}% (".($diff >= 0 ? '+' : '')."{$diff} points on last week).",
            'why' => "Room nights sold: {$now['room_nights']} this week, {$before['room_nights']} the week before.", 'tone' => $diff >= 0 ? 'ok' : 'warn',
            'explain' => self::explain([
                ['Room nights sold, last 7 days', $now['room_nights']],
                ['Room nights sold, the 7 days before', $before['room_nights']],
                ['Rooms in the hotel', $now['rooms']],
                ['Occupancy now / before', $now['occupancy'].'% / '.$before['occupancy'].'%'],
            ], 'Occupancy = room nights sold ÷ (rooms × 7 nights). Only stays that checked in count; cancelled and no-show bookings do not.',
                $diff >= 0 ? null : 'Look at the coming week on the Reservations calendar and follow up pending bookings.'),
        ];
    }

    /** @param  callable(): ?array  $make */
    private function hint(?Hotel $hotel, string $key, callable $make): ?array
    {
        if (! $this->on($hotel, $key)) {
            return null;
        }
        try {
            $h = $make();

            return $h ? ['key' => $key] + $h + ['tone' => 'info', 'link' => null] : null;
        } catch (Throwable $e) {
            report($e); // a hint is never worth an error on screen

            return null;
        }
    }

    /** @param  list<?array>  $hints  @return list<array> */
    private static function list(array $hints): array
    {
        return array_values(array_filter($hints));
    }

    /** "ETB 136,885.00" */
    private static function money(string $currency, string|float $amount): string
    {
        return $currency.' '.number_format((float) $amount, 2);
    }

    /** @param  list<array{0: string, 1: string|int|float}>  $facts */
    private static function explain(array $facts, string $how, ?string $do = null): array
    {
        return ['facts' => array_map(fn ($f) => [$f[0], (string) $f[1]], $facts), 'how' => $how, 'do' => $do];
    }

    private static function pct(float $now, float $before): ?int
    {
        return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
    }

    // ------------------------------------------------------------------ dashboard

    /** @param  list<int>  $branchIds */
    public function dashboard(Hotel $hotel, User $user, array $branchIds): array
    {
        $today = HotelClock::now($hotel)->toDateString();

        return self::list([
            // The week-on-week insight needs ~25 database questions; it changes slowly, so it is kept 5 minutes (QA #120 speed).
            $this->hint($hotel, 'insight_of_the_day', fn () => Cache::remember(
                'smart:insight:'.$hotel->id.':'.implode(',', collect($branchIds)->sort()->values()->all()).':'.(int) $user->hasPermission('dashboard.view_revenue').':'.$today,
                300,
                fn () => $this->insight($hotel, $user, $branchIds, $today),
            )),
            $this->hint($hotel, 'tonight', function () use ($hotel, $branchIds) {
                $rooms = Metrics::for($hotel, $branchIds)->roomCount();
                if ($rooms === 0) {
                    return null;
                }
                $start = HotelClock::now($hotel)->startOfDay()->utc();
                $base = fn () => DB::table('reservation_rooms as rr')->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
                    ->where('r.hotel_id', $hotel->id)->whereIn('r.branch_id', $branchIds);
                // Staying tonight = leaving tomorrow or later (a guest leaving at 12:00 today is not here tonight).
                $inHouse = $base()->where('rr.status', 'checked_in')->where('rr.check_out_at', '>=', $start->copy()->addDay())->count();
                $toCome = $base()->whereIn('rr.status', ['pending', 'confirmed'])->whereBetween('rr.check_in_at', [$start, $start->copy()->addDay()])->count();
                $expected = min(100, (int) round(($inHouse + $toCome) / $rooms * 100));

                return [
                    'text' => "Tonight looks about {$expected}% full".($toCome ? " – {$toCome} more ".($toCome === 1 ? 'arrival' : 'arrivals').' to come.' : '.'),
                    'why' => "{$inHouse} rooms staying tonight plus {$toCome} arriving today, of {$rooms} rooms.", 'link' => '/rooms?tab=arrivals',
                    'explain' => self::explain([
                        ['Rooms with a guest staying tonight', $inHouse],
                        ['Rooms still to arrive today', $toCome],
                        ['Rooms in the hotel', $rooms],
                        ['Expected tonight', $expected.'%'],
                    ], 'Rooms with a guest staying tonight, plus confirmed and pending bookings arriving today, divided by all rooms that can be sold. Guests leaving later today are not counted.',
                        $toCome ? 'Check the Arriving list: call late guests, and make sure their rooms are clean.' : null),
                ];
            }),
        ]);
    }

    // ------------------------------------------------------------------ bookings and front desk

    public function reservation(Hotel $hotel, Reservation $r): array
    {
        $r->loadMissing(['guest', 'rooms.room']);

        return self::list([
            $this->hint($hotel, 'no_show_risk', function () use ($r) {
                if (! in_array($r->status, ['pending', 'confirmed'], true) || $r->check_in_at->lt(now()->subDay())) {
                    return null;
                }
                $why = [];
                $score = 0;
                if (! Money::isPositive((string) $r->paid_total)) {
                    $score += 2;
                    $why[] = 'nothing paid yet';
                }
                $past = Reservation::where('guest_id', $r->guest_id)->where('id', '!=', $r->id)->get(['status']);
                $lost = $past->whereIn('status', ['no_show', 'cancelled'])->count();
                if ($lost > 0) {
                    $score += 2 * min($lost, 2);
                    $why[] = "{$lost} earlier ".($lost === 1 ? 'booking' : 'bookings').' cancelled or not shown';
                }
                if ($past->where('status', 'checked_out')->isEmpty()) {
                    $score++;
                    $why[] = 'first stay';
                }
                if (in_array($r->source, ['phone', 'other'], true)) {
                    $score++;
                    $why[] = "booked by {$r->source}";
                }
                if ($r->created_at && $r->created_at->diffInDays($r->check_in_at) > 30) {
                    $score++;
                    $why[] = 'booked long ago';
                }

                return $score >= 4 ? [
                    'text' => 'This guest may not come. Call to confirm, or ask for a deposit.', 'why' => 'Because: '.implode(', ', $why).'.', 'tone' => 'warn',
                    'explain' => self::explain(
                        array_merge(array_map(fn ($w) => ['Sign', ucfirst($w)], $why), [['Risk points', "{$score} (a warning from 4)"]]),
                        'Points for each sign: nothing paid (2), earlier bookings cancelled or not shown (2 each, up to 4), first stay (1), booked by phone or other (1), booked more than 30 days ago (1).',
                        'Call the guest to confirm, or ask for a deposit. If they do not arrive, mark the booking as a no-show.'),
                ] : null;
            }),
            $this->hint($hotel, 'returning_guest', function () use ($hotel, $r) {
                if (! $r->guest) {
                    return null;
                }
                $past = Reservation::where('guest_id', $r->guest_id)->where('id', '!=', $r->id)->where('status', 'checked_out')->with('rooms.room')->orderByDesc('check_out_at')->get();
                if ($past->isEmpty()) {
                    return null;
                }
                $usual = $past->flatMap(fn ($p) => $p->rooms->map(fn ($x) => $x->room?->number))->filter()->countBy()->sortDesc()->keys()->first();
                $spent = Money::sum($past->pluck('total')->map(fn ($v) => (string) $v)->all());
                $n = $past->count() + 1;
                $nth = $n.(in_array($n % 100, [11, 12, 13], true) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th'));

                return [
                    'text' => "Returning guest – {$nth} stay.".($usual ? " Usually room {$usual}." : '').($r->guest->notes ? " Note: {$r->guest->notes}" : ''),
                    'why' => 'Last stay '.HotelClock::local($past->first()->check_out_at, $hotel)->format('d M Y').', '.self::money($r->currency, $spent).' spent so far.',
                    'tone' => 'ok', 'link' => "/guests/{$r->guest_id}",
                    'explain' => self::explain(array_values(array_filter([
                        ['Earlier stays', $past->count()],
                        ['Last stay', HotelClock::local($past->first()->check_out_at, $hotel)->format('d M Y')],
                        ['Spent so far', self::money($r->currency, $spent)],
                        $usual ? ['Room used most', $usual] : null,
                    ])), 'Stays of this guest that ended with check-out, in all branches.', 'Welcome them back by name, and give their usual room if it is free.'),
                ];
            }),
        ]);
    }

    public function checkIn(Hotel $hotel, Reservation $r, Availability $availability): array
    {
        $r->loadMissing(['rooms.roomType', 'guest']);

        return self::list([
            $this->hint($hotel, 'best_room', function () use ($r, $availability) {
                $previous = Reservation::where('guest_id', $r->guest_id)->where('id', '!=', $r->id)->where('status', 'checked_out')
                    ->with('rooms:id,reservation_id,room_id')->get()->flatMap(fn ($p) => $p->rooms->pluck('room_id'))->filter()->countBy();
                $used = DB::table('reservation_rooms')->where('hotel_id', $r->hotel_id)->whereNotNull('room_id')->where('check_in_at', '>=', now()->subDays(30))
                    ->selectRaw('room_id, COUNT(*) as c')->groupBy('room_id')->pluck('c', 'room_id');
                $picks = [];
                $reasons = [];
                foreach ($r->rooms as $rr) {
                    if ($rr->room_id || $rr->status !== $r->status) {
                        continue;
                    }
                    $free = $availability->freeRooms($r->branch_id, $rr->room_type_id, $rr->check_in_at, $rr->blocked_until ?? $rr->check_out_at, $r->id)
                        ->filter(fn (Room $room) => in_array($room->status, ['available', 'clean'], true) && ! in_array($room->id, $picks, true));
                    if ($free->isEmpty()) {
                        continue;
                    }
                    $best = $free->sortBy(fn (Room $room) => sprintf('%05d-%05d-%s', 99999 - (int) ($previous[$room->id] ?? 0), (int) ($used[$room->id] ?? 0), $room->number))->first();
                    $picks[$rr->id] = $best->id;
                    $reasons[] = "room {$best->number}".(($previous[$best->id] ?? 0) > 0 ? ' (the guest stayed there before)' : ' (clean, used least in the last 30 days)');
                }

                return $picks ? [
                    'text' => 'Suggested: '.implode('; ', $reasons).'.', 'why' => 'Rooms are used evenly so they wear evenly; returning guests get their usual room.', 'data' => ['rooms' => $picks],
                    'explain' => self::explain(array_map(fn ($x) => ['Suggestion', ucfirst($x)], $reasons),
                        'Only clean rooms of the booked type that are free for the whole stay. First a room the guest stayed in before; otherwise the room used least in the last 30 days, so all rooms wear evenly.',
                        'Pick the suggested room, or any other free room if the guest asks.'),
                ] : null;
            }),
            $this->hint($hotel, 'upgrade', function () use ($hotel, $r, $availability) {
                if ($r->stay_type !== 'nightly' || $r->rooms->count() !== 1) {
                    return null;
                }
                $rr = $r->rooms->first();
                $better = RoomType::where('branch_id', $r->branch_id)->where('nightly_price', '>', $rr->roomType?->nightly_price ?? 0)
                    ->where('max_capacity', '>=', $r->adults + $r->children)->orderBy('nightly_price')->first();
                if (! $better) {
                    return null;
                }
                $free = $availability->availableCount($better, $rr->check_in_at, $rr->blocked_until ?? $rr->check_out_at, $r->id);
                $rooms = Metrics::for($hotel, [$r->branch_id])->roomCount();
                $inHouse = DB::table('reservation_rooms as rr')->join('reservations as res', 'res.id', '=', 'rr.reservation_id')
                    ->where('res.branch_id', $r->branch_id)->where('rr.status', 'checked_in')->count();
                $occupancy = $rooms ? $inHouse / $rooms * 100 : 100;

                return $free >= 2 && $occupancy < 60 ? [
                    'text' => "Quiet night: {$better->name} has {$free} free rooms. A free upgrade makes a good impression.",
                    'why' => 'Only '.round($occupancy).'% of rooms are occupied now.', 'tone' => 'ok',
                    'explain' => self::explain([
                        ['Booked room type', $rr->roomType?->name ?? '—'],
                        ['Next better type', $better->name],
                        ["Free {$better->name} rooms for the stay", $free],
                        ['Rooms occupied now', round($occupancy).'%'],
                    ], 'Shown on quiet nights only: under 60% occupied and at least 2 free rooms of the next better type that fits the guests.',
                        'Offer the upgrade at the same price, then move the room after check-in. Nothing changes unless you do it.'),
                ] : null;
            }),
        ]);
    }

    /** Duplicate guest check while typing (same phone, ID number or name). */
    public function guestCheck(Hotel $hotel, array $q): array
    {
        return self::list([$this->hint($hotel, 'duplicate_guest', function () use ($q) {
            $digits = substr((string) preg_replace('/\D/', '', (string) ($q['phone'] ?? '')), -9);
            $first = trim((string) ($q['first_name'] ?? ''));
            $last = trim((string) ($q['last_name'] ?? ''));
            $byPhone = strlen($digits) === 9;
            $byId = trim((string) ($q['id_number'] ?? '')) !== '';
            $byName = $first !== '' && $last !== '';
            if (! $byPhone && ! $byId && ! $byName) {
                return null;
            }
            // Phones are stored as typed: compare the last 9 digits in PHP over the few candidates.
            $candidates = Guest::query()
                ->when(! empty($q['exclude_id']), fn ($w) => $w->where('id', '!=', (int) $q['exclude_id']))
                ->where(function ($w) use ($byPhone, $digits, $byId, $q, $byName, $first, $last) {
                    $w->whereRaw('1 = 0');
                    if ($byPhone) {
                        $w->orWhere('phone', 'like', '%'.substr($digits, -4));
                    }
                    if ($byId) {
                        $w->orWhere('id_number_hash', Guest::idHash((string) $q['id_number']));
                    }
                    if ($byName) {
                        $w->orWhere(fn ($n) => $n->where('first_name', $first)->where('last_name', $last));
                    }
                })->limit(50)->get();
            $g = $candidates->first(fn (Guest $c) => ($byPhone && $c->phone && substr((string) preg_replace('/\D/', '', $c->phone), -9) === $digits)
                || ($byId && $c->id_number === trim((string) $q['id_number']))
                || ($byName && mb_strtolower($c->first_name) === mb_strtolower($first) && mb_strtolower($c->last_name) === mb_strtolower($last)));
            if (! $g) {
                return null;
            }

            return [
                'text' => "{$g->full_name} may already exist".($g->phone ? " ({$g->phone})" : '').'.',
                'why' => 'Same phone number, ID number or name. Use the existing guest to keep their history together.',
                'tone' => 'warn', 'link' => "/guests/{$g->id}", 'data' => ['guest_id' => $g->id, 'name' => $g->full_name],
                'explain' => self::explain(array_values(array_filter([
                    ['Existing guest', $g->full_name],
                    $byPhone && $g->phone && substr((string) preg_replace('/\D/', '', $g->phone), -9) === $digits ? ['Same phone', $g->phone] : null,
                    $byId && $g->id_number === trim((string) $q['id_number']) ? ['Same ID number', 'Yes'] : null,
                    $byName && mb_strtolower($g->first_name) === mb_strtolower($first) && mb_strtolower((string) $g->last_name) === mb_strtolower($last) ? ['Same name', $g->full_name] : null,
                ])), 'We compare the last 9 digits of the phone, the exact ID number and the full name with guests already saved.',
                    'Open the existing guest and book with them, so all stays and bills stay in one place. If it is a different person, carry on.'),
            ];
        })]);
    }

    // ------------------------------------------------------------------ money, housekeeping, staff

    public function cashier(Hotel $hotel, User $user, Cashier $cashier): array
    {
        return self::list([$this->hint($hotel, 'cash_prediction', function () use ($hotel, $user, $cashier) {
            $session = $cashier->current($user);
            if (! $session) {
                return null;
            }
            $base = $hotel->default_currency;
            $now = $cashier->expected($session)[$base]['expected'] ?? '0.00';
            // What usually comes in cash in the rest of an 8-hour shift on this weekday (last 4 weeks).
            $end = $session->opened_at->copy()->addHours(8);
            $more = '0.00';
            if ($end->gt(now())) {
                $sum = '0.00';
                for ($w = 1; $w <= 4; $w++) {
                    $v = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->where('p.hotel_id', $hotel->id)
                        ->where('p.branch_id', $session->branch_id)->where('m.type', 'cash')->where('p.currency', $base)
                        ->whereBetween('p.paid_at', [now()->subWeeks($w), $end->copy()->subWeeks($w)])
                        ->selectRaw("SUM(CASE WHEN p.type = 'refund' THEN -p.amount ELSE p.amount END) as t")->value('t');
                    $sum = Money::add($sum, (string) ($v ?? '0'));
                }
                $more = Money::round(bcdiv($sum, '4', 4));
            }
            $total = Money::add($now, $more);

            return [
                'text' => "At the end of your shift you should have about {$base} ".number_format((float) $total).' in the drawer.',
                'why' => self::money($base, $now).' now (start + cash taken), plus about '.self::money($base, $more).' that usually comes in by '.HotelClock::local($end, $hotel)->format('H:i').' on this weekday.',
                'explain' => self::explain([
                    ['In the drawer now (start + cash taken − refunds)', self::money($base, $now)],
                    ['Usually still to come by '.HotelClock::local($end, $hotel)->format('H:i'), self::money($base, $more)],
                    ['Expected at the end', self::money($base, $total)],
                ], 'Cash that came in at this branch in the same hours on the same weekday, over the last 4 weeks, averaged. An 8-hour shift is assumed.',
                    'Count the drawer at the end and compare. Only the count you enter when closing is saved.'),
            ];
        })]);
    }

    public function housekeeping(Hotel $hotel, int $branchId): array
    {
        return self::list([$this->hint($hotel, 'cleaning_order', function () use ($hotel, $branchId) {
            $tasks = HousekeepingTask::where('branch_id', $branchId)->whereIn('status', ['pending', 'in_progress'])->where('type', 'cleaning')->with('room:id,number,floor')->get();
            if ($tasks->isEmpty()) {
                return null;
            }
            $start = HotelClock::now($hotel)->startOfDay()->utc();
            $arrivals = DB::table('reservation_rooms')->where('hotel_id', $hotel->id)->whereIn('status', ['pending', 'confirmed'])
                ->whereBetween('check_in_at', [$start, $start->copy()->addDay()])->whereNotNull('room_id')->pluck('check_in_at', 'room_id');
            $ordered = $tasks->sortBy(fn (HousekeepingTask $t) => (isset($arrivals[$t->room_id]) ? '0'.$arrivals[$t->room_id] : '1').'|'.str_pad((string) ($t->room?->floor ?? ''), 4, '0', STR_PAD_LEFT).'|'.str_pad((string) $t->room?->number, 6, '0', STR_PAD_LEFT))->values();
            $done = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)->where('type', 'cleaning')->where('status', 'done')
                ->whereNotNull('started_at')->whereNotNull('completed_at')->where('completed_at', '>=', now()->subDays(30))->get(['started_at', 'completed_at']);
            $avg = $done->isEmpty() ? 30 : max(5, (int) round($done->avg(fn ($d) => Carbon::parse($d->started_at)->diffInMinutes(Carbon::parse($d->completed_at)))));
            $people = max(1, User::where('hotel_id', $hotel->id)->where('status', 'active')->whereHas('role', fn ($q) => $q->where('key', 'housekeeping'))->count());
            $minutes = (int) ceil($tasks->count() * $avg / $people);
            $first = $ordered->take(3)->map(fn ($t) => $t->room?->number)->filter()->implode(', ');
            $soon = $ordered->filter(fn ($t) => isset($arrivals[$t->room_id]))->count();

            return [
                'text' => "Clean {$first} first".($soon ? " ({$soon} with guests arriving today)" : '').'. About '.intdiv($minutes, 60).' h '.($minutes % 60).' min for '.$tasks->count().' rooms.',
                'why' => "Rooms with a guest arriving today come first, then floor by floor. A room takes about {$avg} min here; {$people} ".($people === 1 ? 'housekeeper' : 'housekeepers').'.',
                'data' => ['order' => $ordered->pluck('id')],
                'explain' => self::explain([
                    ['Rooms to clean', $tasks->count()],
                    ['With a guest arriving today', $soon],
                    ['Average time per room (last 30 days)', "{$avg} min"],
                    ['Housekeepers', $people],
                    ['Order', $ordered->map(fn ($t) => $t->room?->number)->filter()->implode(', ')],
                ], 'Rooms with a guest arriving today first (earliest first), then floor by floor. Time = rooms × average minutes ÷ housekeepers.',
                    'Start with the first rooms in the list. Mark each room done so reception sees it is ready.'),
            ];
        })]);
    }

    public function shifts(Hotel $hotel, int $branchId): array
    {
        return self::list([$this->hint($hotel, 'staffing', function () use ($hotel, $branchId) {
            $today = HotelClock::now($hotel)->startOfDay();
            $days = collect(range(1, 7))->map(function ($i) use ($today, $branchId) {
                $d = $today->copy()->addDays($i);
                $from = $d->copy()->utc();
                $to = $from->copy()->addDay();
                $moves = Reservation::where('branch_id', $branchId)->whereIn('status', ['pending', 'confirmed', 'checked_in'])
                    ->where(fn ($q) => $q->whereBetween('check_in_at', [$from, $to])->orWhereBetween('check_out_at', [$from, $to]))->count();

                return ['date' => $d, 'moves' => $moves];
            });
            $busiest = $days->sortByDesc('moves')->first();
            $others = $days->reject(fn ($d) => $d['date']->eq($busiest['date']))->avg('moves') ?? 0;
            if ($busiest['moves'] < 8 || $busiest['moves'] < 1.5 * max(1, $others)) {
                return null;
            }

            return [
                'text' => $busiest['date']->format('l j M').' looks busy: '.$busiest['moves'].' check-ins and check-outs. Consider one more person at reception.',
                'why' => 'The other days of the next week average '.round($others).'.', 'tone' => 'warn',
                'explain' => self::explain(array_merge(
                    $days->map(fn ($d) => [$d['date']->format('D j M'), $d['moves'].' check-ins and check-outs'])->all(),
                ), 'Guests booked to arrive and leave on each of the next 7 days. A day is called busy with at least 8 and 1.5 times the other days.',
                    'Add a person to the roster for that day, or move a day off.'),
            ];
        })]);
    }

    // ------------------------------------------------------------------ reports, settings, Super Admin

    /** @param  list<int>  $branchIds */
    public function report(Hotel $hotel, array $branchIds, string $from, string $to): array
    {
        return self::list([$this->hint($hotel, 'report_summary', function () use ($hotel, $branchIds, $from, $to) {
            $m = Metrics::for($hotel, $branchIds);
            $length = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
            $now = $m->kpis($from, $to);
            $before = $m->kpis(Carbon::parse($from)->subDays($length)->toDateString(), Carbon::parse($from)->subDay()->toDateString());
            if ($now['room_nights'] === 0 && ! Money::isPositive($now['revenue'])) {
                return null;
            }
            $occ = (int) round($now['occupancy'] - $before['occupancy']);
            $rev = self::pct((float) $now['revenue'], (float) $before['revenue']);
            $why = "Compared with the {$length} days before.";
            $cancelNow = (int) round($now['bookings'] * $now['cancellation_rate'] / 100);
            $cancelBefore = (int) round($before['bookings'] * $before['cancellation_rate'] / 100);
            if ($occ <= -5 && $cancelNow > $cancelBefore + 2) {
                $why .= " Partly because of {$cancelNow} cancellations, against {$cancelBefore} before.";
            }

            return [
                'text' => "Occupancy {$now['occupancy']}% (".($occ >= 0 ? '+' : '')."{$occ} points), revenue {$hotel->default_currency} ".number_format((float) $now['revenue']).($rev !== null ? ' ('.($rev >= 0 ? '+' : '')."{$rev}%)" : '').'.',
                'why' => $why, 'tone' => ($rev ?? $occ) >= 0 ? 'ok' : 'warn',
                'explain' => self::explain([
                    ['Occupancy, this period / before', $now['occupancy'].'% / '.$before['occupancy'].'%'],
                    ['Revenue, this period', self::money($hotel->default_currency, $now['revenue'])],
                    ['Revenue, the period before', self::money($hotel->default_currency, $before['revenue'])],
                    ['Bookings made / cancelled', $now['bookings'].' / '.$cancelNow.' (before: '.$before['bookings'].' / '.$cancelBefore.')'],
                ], "The dates you chose ({$length} days) compared with the same number of days just before them."),
            ];
        })]);
    }

    public function roomsSettings(Hotel $hotel): array
    {
        return self::list([$this->hint($hotel, 'plan_usage', function () use ($hotel) {
            $limit = $hotel->limit('rooms');
            if (! $limit) {
                return null;
            }
            $used = DB::table('rooms')->where('hotel_id', $hotel->id)->whereNull('deleted_at')->count();
            $left = $limit - $used;

            return $used >= $limit * 0.75 ? [
                'text' => $left > 0 ? "You can add {$left} more ".($left === 1 ? 'room' : 'rooms').' on your plan.' : 'You have reached the room limit of your plan.',
                'why' => "{$used} of {$limit} rooms used.", 'tone' => $left > 0 ? 'info' : 'warn', 'link' => '/settings/subscription',
                'explain' => self::explain([['Rooms on your plan', $limit], ['Rooms added', $used], ['Still possible', max(0, $left)]],
                    'Shown when 75% or more of the rooms on your plan are used.', 'To add more rooms, ask HotelMS for a bigger plan.'),
            ] : null;
        })]);
    }

    public function admin(): array
    {
        return self::list([$this->hint(null, 'platform_insight', function () {
            $ids = DB::table('hotels')->where('is_demo', false)->whereNull('deleted_at')->pluck('id');
            if ($ids->isEmpty()) {
                return null;
            }
            $today = now('Africa/Addis_Ababa')->startOfDay()->utc();
            $now = DB::table('reservations')->whereIn('hotel_id', $ids)->whereBetween('created_at', [$today, now()])->count();
            $then = DB::table('reservations')->whereIn('hotel_id', $ids)->whereBetween('created_at', [$today->copy()->subWeek(), now()->subWeek()])->count();
            $quiet = DB::table('hotels')->whereIn('id', $ids)->whereIn('status', ['trial', 'active'])
                ->whereNotExists(fn ($q) => $q->from('activity_logs')->whereColumn('activity_logs.hotel_id', 'hotels.id')->where('activity_logs.created_at', '>=', now()->subDays(7)))->count();
            $change = self::pct($now, $then);

            return [
                'text' => "{$now} bookings made across hotels so far today".($change !== null ? ' ('.($change >= 0 ? '+' : '')."{$change}% on last week)" : '').'.'
                    .($quiet ? " {$quiet} ".($quiet === 1 ? 'hotel has' : 'hotels have').' not used the system for 7 days.' : ''),
                'why' => "Same time last week: {$then} bookings.", 'tone' => $quiet ? 'warn' : 'ok', 'link' => $quiet ? '/admin/reports' : null,
                'explain' => self::explain([
                    ['Bookings made today so far', $now],
                    ['Same time last week', $then],
                    ['Hotels quiet for 7 days', $quiet],
                ], 'All hotels except demo hotels, Addis Ababa time. "Quiet" means no change of any kind in the hotel for 7 days.',
                    $quiet ? 'Call the quiet hotels: they may need help or be about to leave.' : null),
            ];
        })]);
    }
}

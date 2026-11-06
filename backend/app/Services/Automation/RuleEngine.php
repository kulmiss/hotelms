<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Hotel;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Switches;
use App\Support\HotelClock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The hotel's own rules: "When (trigger) … if (conditions) … then (actions)" (automation.md → Rule builder).
 * Rules never delete data or move money on their own.
 */
class RuleEngine
{
    public const TRIGGERS = ['booking_created', 'booking_confirmed', 'booking_cancelled', 'checked_in', 'checked_out', 'payment_received', 'room_status_changed', 'daily'];

    /** field => which triggers offer it */
    public const FIELDS = [
        'branch_id' => 'all', 'room_type_id' => 'booking', 'guest_type_id' => 'booking', 'nationality' => 'booking', 'source' => 'booking',
        'stay_type' => 'booking', 'nights' => 'booking', 'amount' => 'money', 'weekday' => 'all', 'room_status' => 'room',
        'payment_method_type' => 'payment',
    ];

    public const OPS = ['is', 'is_not', 'above', 'below'];

    public const ACTIONS = ['notify', 'booking_note', 'housekeeping_task', 'room_status'];

    public function __construct(private Automations $automations, private TenantContext $tenant, private Switches $switches) {}

    /** Called when something happens (from the automation observer). */
    public function fire(Hotel $hotel, string $trigger, array $facts, ?Reservation $reservation = null, ?Room $room = null): int
    {
        if ($hotel->is_demo || ! $this->switches->on($hotel, 'feature.automations')) {
            return 0;
        }
        $fired = 0;
        $rules = $this->tenant->runAs($hotel, fn () => AutomationRule::where('trigger', $trigger)->where('enabled', true)->get());
        foreach ($rules as $rule) {
            if (! $this->matches($rule->conditions ?? [], $facts)) {
                continue;
            }
            try {
                $this->tenant->runAs($hotel, fn () => $this->act($hotel, $rule, $facts, $reservation, $room));
                $rule->forceFill(['fired_count' => $rule->fired_count + 1, 'last_fired_at' => now()])->saveQuietly();
                $fired++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $fired;
    }

    /** @param list<array{field: string, op: string, value: mixed}> $conditions */
    public function matches(array $conditions, array $facts): bool
    {
        foreach ($conditions as $c) {
            $have = $facts[$c['field']] ?? null;
            $want = $c['value'] ?? null;
            $ok = match ($c['op']) {
                'is' => is_array($have) ? in_array((string) $want, array_map('strval', $have), true) : (string) $have === (string) $want,
                'is_not' => is_array($have) ? ! in_array((string) $want, array_map('strval', $have), true) : (string) $have !== (string) $want,
                'above' => is_numeric($have) && is_numeric($want) && bccomp((string) $have, (string) $want, 2) > 0,
                'below' => is_numeric($have) && is_numeric($want) && bccomp((string) $have, (string) $want, 2) < 0,
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function act(Hotel $hotel, AutomationRule $rule, array $facts, ?Reservation $reservation, ?Room $room): void
    {
        foreach ($rule->actions as $a) {
            $text = trim((string) ($a['message'] ?? '')) ?: $rule->name;
            match ($a['type'] ?? null) {
                'notify' => $this->automations->tell(
                    $hotel, "rule:{$rule->id}", $this->who($hotel, $a, $facts['branch_id'] ?? null),
                    $text, $facts['summary'] ?? null, $facts['link'] ?? null, (bool) ($a['urgent'] ?? false),
                ),
                'booking_note' => $reservation?->forceFill(['notes' => trim(($reservation->notes ? $reservation->notes."\n" : '').'['.$rule->name.'] '.$text)])->saveQuietly(),
                'housekeeping_task' => $room ? HousekeepingTask::create([
                    'branch_id' => $room->branch_id, 'room_id' => $room->id, 'reservation_id' => $reservation?->id,
                    'type' => in_array($a['task'] ?? 'cleaning', ['cleaning', 'inspection', 'maintenance'], true) ? $a['task'] : 'cleaning',
                    'priority' => 'normal', 'status' => 'pending', 'notes' => $text,
                ]) : null,
                // Only to a status that cannot start a loop with "room status changed" rules of the same kind.
                'room_status' => $room && in_array($a['status'] ?? '', ['dirty', 'maintenance'], true) && $room->status !== $a['status']
                    ? $room->forceFill(['status' => $a['status']])->saveQuietly() : null,
                default => null,
            };
        }
    }

    /** @return Collection<int, User> */
    private function who(Hotel $hotel, array $action, ?int $branchId): Collection
    {
        $users = collect();
        if (! empty($action['permission'])) {
            $users = $users->merge($this->automations->people($hotel, [(string) $action['permission']], $branchId));
        }
        if (! empty($action['user_ids'])) {
            $users = $users->merge(User::where('hotel_id', $hotel->id)->where('status', 'active')->whereIn('id', (array) $action['user_ids'])->get());
        }

        return $users->isEmpty() ? $this->automations->people($hotel, ['hotel_admin']) : $users;
    }

    // ------------------------------------------------------------------ facts

    /** What a rule can look at for a booking. */
    public static function bookingFacts(Reservation $r, Hotel $hotel): array
    {
        $r->loadMissing(['guest', 'rooms']);

        return [
            'branch_id' => $r->branch_id,
            'room_type_id' => $r->rooms->pluck('room_type_id')->unique()->values()->all(),
            'guest_type_id' => $r->guest?->guest_type_id,
            'nationality' => $r->guest?->nationality,
            'source' => $r->source,
            'stay_type' => $r->stay_type,
            'nights' => max(1, (int) HotelClock::local($r->check_in_at, $hotel)->startOfDay()->diffInDays(HotelClock::local($r->check_out_at, $hotel)->startOfDay())),
            'amount' => (string) $r->total,
            'weekday' => (int) HotelClock::now($hotel)->isoWeekday(),
            'summary' => "{$r->code} · ".($r->guest?->full_name ?? ''),
            'link' => "/reservations/{$r->id}",
        ];
    }

    public static function paymentFacts(Payment $p, Hotel $hotel): array
    {
        $p->loadMissing('method');

        return [
            'branch_id' => $p->branch_id, 'amount' => (string) $p->amount, 'payment_method_type' => $p->method?->type,
            'weekday' => (int) HotelClock::now($hotel)->isoWeekday(),
            'summary' => "{$p->currency} {$p->amount}".($p->method ? " · {$p->method->name}" : ''),
            'link' => $p->reservation_id ? "/reservations/{$p->reservation_id}" : null,
        ];
    }

    public static function roomFacts(Room $room, Hotel $hotel): array
    {
        return [
            'branch_id' => $room->branch_id, 'room_status' => $room->status, 'weekday' => (int) HotelClock::now($hotel)->isoWeekday(),
            'summary' => "Room {$room->number}: {$room->status}", 'link' => '/rooms?branch='.$room->branch_id.'&room='.$room->id,
        ];
    }

    /**
     * "This would have fired N times in the last 7 days" – the rule checked against past records.
     *
     * @param list<array{field: string, op: string, value: mixed}> $conditions
     */
    public function test(Hotel $hotel, string $trigger, array $conditions): int
    {
        $since = now()->subDays(7);

        return $this->tenant->runAs($hotel, function () use ($hotel, $trigger, $conditions, $since) {
            $count = fn (iterable $facts) => collect($facts)->filter(fn ($f) => $this->matches($conditions, $f))->count();

            return match ($trigger) {
                'booking_created' => $count(Reservation::where('created_at', '>=', $since)->get()->map(fn ($r) => self::bookingFacts($r, $hotel))),
                'booking_confirmed' => $count(Reservation::whereIn('status', ['confirmed', 'checked_in', 'checked_out'])->where('updated_at', '>=', $since)->get()->map(fn ($r) => self::bookingFacts($r, $hotel))),
                'booking_cancelled' => $count(Reservation::whereIn('status', ['cancelled', 'no_show'])->where('cancelled_at', '>=', $since)->get()->map(fn ($r) => self::bookingFacts($r, $hotel))),
                'checked_in' => $count(Reservation::whereHas('rooms', fn ($q) => $q->where('actual_check_in_at', '>=', $since))->get()->map(fn ($r) => self::bookingFacts($r, $hotel))),
                'checked_out' => $count(Reservation::whereHas('rooms', fn ($q) => $q->where('actual_check_out_at', '>=', $since))->get()->map(fn ($r) => self::bookingFacts($r, $hotel))),
                'payment_received' => $count(Payment::where('type', '!=', 'refund')->where('paid_at', '>=', $since)->get()->map(fn ($p) => self::paymentFacts($p, $hotel))),
                'daily' => $this->matches($conditions, ['weekday' => (int) HotelClock::now($hotel)->isoWeekday()]) ? 7 : 0,
                default => 0, // room status history is not kept per change
            };
        });
    }

    /** Daily rules: run once a day at the chosen time. */
    public function runDaily(Hotel $hotel): int
    {
        $now = HotelClock::now($hotel);
        $fired = 0;
        $rules = $this->tenant->runAs($hotel, fn () => AutomationRule::where('trigger', 'daily')->where('enabled', true)->get());
        foreach ($rules as $rule) {
            $time = (string) collect($rule->conditions ?? [])->firstWhere('field', 'time')['value'] ?? '08:00';
            if ($now->format('H:i') < $time || ! $this->automations->once($hotel, "rule:{$rule->id}", $now->toDateString())) {
                continue;
            }
            $conditions = array_values(array_filter($rule->conditions ?? [], fn ($c) => ($c['field'] ?? '') !== 'time'));
            if ($this->matches($conditions, ['weekday' => (int) $now->isoWeekday()])) {
                $this->tenant->runAs($hotel, fn () => $this->act($hotel, $rule, [], null, null));
                $rule->forceFill(['fired_count' => $rule->fired_count + 1, 'last_fired_at' => now()])->saveQuietly();
                $fired++;
            }
        }

        return $fired;
    }

    public static function dayStart(Hotel $hotel): Carbon
    {
        return HotelClock::now($hotel)->startOfDay()->utc();
    }
}

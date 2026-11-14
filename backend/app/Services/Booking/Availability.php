<?php

namespace App\Services\Booking;

use App\Models\IcalBlock;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which rooms are free for a time range (overbooking is blocked, workflows.md Q10).
 * A booking holds its room from check-in until "blocked_until" (check-out plus
 * cleaning time for hourly / day-use stays). Rooms that are out of order are not offered.
 * Busy dates imported from Airbnb, Booking.com… (iCal) hold a room, or one room of a type, the same way.
 */
class Availability
{
    /** Room statuses that cannot be sold. */
    public const UNSELLABLE = ['out_of_order'];

    /**
     * Per room type of a branch: rooms, the most booked at the same moment, and how many are left.
     *
     * @return Collection<int, array{room_type: RoomType, total: int, booked: int, available: int}>
     */
    public function forBranch(int $branchId, CarbonInterface $from, CarbonInterface $until, ?int $ignoreReservationId = null): Collection
    {
        $types = RoomType::where('branch_id', $branchId)->orderBy('nightly_price')->get();
        $totals = Room::where('branch_id', $branchId)->whereNotIn('status', self::UNSELLABLE)
            ->selectRaw('room_type_id, count(*) as c')->groupBy('room_type_id')->pluck('c', 'room_type_id');
        $holds = $this->overlapping($from, $until, $ignoreReservationId)
            ->whereIn('room_type_id', $types->pluck('id'))->get(['room_type_id', 'check_in_at', 'blocked_until'])
            ->concat($this->blocks($from, $until)->whereIn('room_type_id', $types->pluck('id'))->get()
                ->map(fn (IcalBlock $b) => (object) ['room_type_id' => $b->room_type_id, 'check_in_at' => $b->starts_at, 'blocked_until' => $b->ends_at]))
            ->groupBy('room_type_id');

        return $types->map(function (RoomType $type) use ($totals, $holds, $from, $until) {
            $total = (int) ($totals[$type->id] ?? 0);
            $booked = $this->peak($holds->get($type->id, collect()), $from, $until);

            return ['room_type' => $type, 'total' => $total, 'booked' => $booked, 'available' => max(0, $total - $booked)];
        })->values();
    }

    public function availableCount(RoomType $type, CarbonInterface $from, CarbonInterface $until, ?int $ignoreReservationId = null): int
    {
        return (int) $this->forBranch($type->branch_id, $from, $until, $ignoreReservationId)
            ->firstWhere(fn ($row) => $row['room_type']->id === $type->id)['available'];
    }

    /**
     * Room numbers of a type (or the whole branch) that nobody holds in the range.
     *
     * @return Collection<int, Room>
     */
    public function freeRooms(int $branchId, ?int $roomTypeId, CarbonInterface $from, CarbonInterface $until, ?int $ignoreReservationId = null): Collection
    {
        $taken = $this->overlapping($from, $until, $ignoreReservationId)->whereNotNull('room_id')->pluck('room_id')
            ->concat($this->blocks($from, $until)->whereNotNull('room_id')->pluck('room_id'));

        return Room::where('branch_id', $branchId)
            ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->whereNotIn('status', self::UNSELLABLE)
            ->whereNotIn('id', $taken)
            ->orderByRaw('LENGTH(number)')->orderBy('number')
            ->get();
    }

    public function roomIsFree(Room $room, CarbonInterface $from, CarbonInterface $until, ?int $ignoreReservationId = null): bool
    {
        return ! in_array($room->status, self::UNSELLABLE, true)
            && ! $this->overlapping($from, $until, $ignoreReservationId)->where('room_id', $room->id)->exists()
            && ! $this->blocks($from, $until)->where('room_id', $room->id)->exists();
    }

    /** Imported busy dates (iCal) that touch [from, until). */
    public function blocks(CarbonInterface $from, CarbonInterface $until)
    {
        return IcalBlock::query()->where('starts_at', '<', $until)->where('ends_at', '>', $from)
            ->whereHas('feed', fn ($q) => $q->where('is_active', true));
    }

    /** Bookings that hold a room at some moment of [from, until). */
    private function overlapping(CarbonInterface $from, CarbonInterface $until, ?int $ignoreReservationId)
    {
        return ReservationRoom::query()
            ->whereIn('status', Reservation::ACTIVE)
            ->where('check_in_at', '<', $until)
            ->where('blocked_until', '>', $from)
            ->when($ignoreReservationId, fn ($q, $id) => $q->where('reservation_id', '!=', $id));
    }

    /**
     * Most holds at the same moment inside the range (a sweep over start/end times),
     * so two bookings on different nights of the range only use one room.
     *
     * @param  Collection<int, ReservationRoom|object>  $holds
     */
    private function peak(Collection $holds, CarbonInterface $from, CarbonInterface $until): int
    {
        $events = [];
        foreach ($holds as $h) {
            $start = max($h->check_in_at->getTimestamp(), $from->getTimestamp());
            $end = min($h->blocked_until->getTimestamp(), $until->getTimestamp());
            if ($start < $end) {
                $events[] = [$start, 1];
                $events[] = [$end, -1];
            }
        }
        // Ends before starts at the same moment (a room freed at 12:00 can be sold from 12:00).
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $now = $max = 0;
        foreach ($events as [, $delta]) {
            $now += $delta;
            $max = max($max, $now);
        }

        return $max;
    }
}

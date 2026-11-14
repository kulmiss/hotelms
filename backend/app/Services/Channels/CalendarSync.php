<?php

namespace App\Services\Channels;

use App\Models\Hotel;
use App\Models\IcalFeed;
use App\Models\Room;
use App\Services\Switches;

/** How many rooms use calendar sync, and the plan's limit (Free: none, Starter: 10, Pro and Business: all). */
class CalendarSync
{
    /** null = no limit */
    public function limit(Hotel $hotel): ?int
    {
        $v = app(Switches::class)->value($hotel, 'limit.ical_rooms'); // read now: a plan can change between requests

        return $v === null ? null : (int) $v;
    }

    /** Rooms with an imported link (directly or through their type) or an export link. @return list<int> */
    public function roomsInUse(): array
    {
        $feeds = IcalFeed::get(['room_id', 'room_type_id']);
        $ids = $feeds->pluck('room_id')->filter()
            ->merge(Room::whereIn('room_type_id', $feeds->pluck('room_type_id')->filter())->pluck('id'))
            ->merge(Room::whereNotNull('ical_token')->pluck('id'));

        return $ids->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    /** Would these rooms push the hotel over its plan's limit? */
    public function wouldExceed(Hotel $hotel, array $roomIds): bool
    {
        $limit = $this->limit($hotel);
        if ($limit === null) {
            return false;
        }

        return count(array_unique([...$this->roomsInUse(), ...array_map('intval', $roomIds)])) > $limit;
    }
}

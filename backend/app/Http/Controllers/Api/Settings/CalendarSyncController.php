<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\IcalFeed;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\Channels\CalendarSync;
use App\Services\Channels\IcalExport;
use App\Services\Channels\IcalSync;
use App\Support\HotelClock;
use App\Support\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Calendar sync (owner, 14 Nov 2026): import Airbnb / Booking.com… links per room or room type,
 * the sync status of each, "Sync now", clashes, and each room's secret export link.
 */
class CalendarSyncController extends Controller
{
    public function __construct(private CalendarSync $limits, private IcalSync $sync) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;
        $branches = $user->accessibleBranches()->pluck('id');
        $feeds = IcalFeed::with(['room:id,number', 'roomType:id,name'])->whereIn('branch_id', $branches)->orderBy('name')->get();
        $rooms = Room::with('roomType:id,name')->whereIn('branch_id', $branches)->orderByRaw('LENGTH(number)')->orderBy('number')->get();

        return response()->json([
            'data' => $feeds->map(fn (IcalFeed $f) => [
                'id' => $f->id, 'name' => $f->name, 'branch_id' => $f->branch_id, 'room_id' => $f->room_id, 'room_type_id' => $f->room_type_id,
                'room' => $f->room?->number, 'room_type' => $f->roomType?->name, 'is_active' => $f->is_active,
                'host' => parse_url((string) $f->url, PHP_URL_HOST), // never the whole secret link
                'last_synced_at' => HotelClock::iso($f->last_synced_at, $hotel), 'last_status' => $f->last_status, 'last_error' => $f->last_error,
                'events_count' => $f->events_count, 'conflicts' => $f->conflicts ?? [],
            ]),
            'rooms' => $rooms->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number, 'branch_id' => $r->branch_id, 'room_type_id' => $r->room_type_id, 'room_type' => $r->roomType?->name, 'export_url' => IcalExport::url($r)]),
            'limit' => ['used' => count($this->limits->roomsInUse()), 'max' => $this->limits->limit($hotel)],
            'every_minutes' => IcalSync::EVERY_MINUTES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $feed = IcalFeed::create($data + ['is_active' => true]);
        $result = $this->sync->sync($feed);

        return response()->json(['data' => ['id' => $feed->id], 'sync' => $result], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $feed = $this->find($request, $id);
        $data = $this->validated($request, $feed);
        $feed->update($data);
        $result = isset($data['url']) && $feed->is_active ? $this->sync->sync($feed) : null;

        return response()->json(['data' => ['id' => $feed->id], 'sync' => $result]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->delete(); // its busy dates go with it

        return response()->json(['message' => 'Deleted.']);
    }

    /** "Sync now" (at most once a minute per link). */
    public function syncNow(Request $request, string $id): JsonResponse
    {
        $feed = $this->find($request, $id);
        if ($feed->last_synced_at && $feed->last_synced_at->gt(now()->subMinute())) {
            throw ValidationException::withMessages(['sync' => 'It was synced less than a minute ago. Try again in a minute.']);
        }

        return response()->json(['sync' => $this->sync->sync($feed)]);
    }

    /** A new secret export link for a room (the old link stops working). */
    public function export(Request $request, string $roomId): JsonResponse
    {
        $room = $this->room($request, $roomId);
        if (! $room->ical_token && $this->limits->wouldExceed($request->user()->hotel, [$room->id])) {
            $this->refuseLimit($request);
        }
        IcalExport::newSecret($room);
        app(ActivityLogger::class)->log('ical.export_link', "New calendar export link for room {$room->number}", $room);

        return response()->json(['export_url' => IcalExport::url($room->fresh())]);
    }

    public function stopExport(Request $request, string $roomId): JsonResponse
    {
        $room = $this->room($request, $roomId);
        $room->forceFill(['ical_token' => null, 'ical_secret' => null])->save();
        app(ActivityLogger::class)->log('ical.export_stopped', "Calendar export link of room {$room->number} turned off", $room);

        return response()->json(['export_url' => null]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?IcalFeed $feed): array
    {
        $user = $request->user();
        $branches = $user->accessibleBranches()->pluck('id');
        $rooms = Room::whereIn('branch_id', $branches)->pluck('id')->all();
        $types = RoomType::whereIn('branch_id', $branches)->pluck('id')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => [$feed ? 'nullable' : 'required', 'string', 'max:2000', 'url:https,http'],
            'room_id' => ['nullable', 'integer', Rule::in($rooms), 'required_without:room_type_id'],
            'room_type_id' => ['nullable', 'integer', Rule::in($types), 'required_without:room_id'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'url.url' => 'Paste the calendar link (iCal / .ics) from the other site. It starts with https://',
            'room_id.required_without' => 'Choose the room or the room type this calendar is for.',
            'room_type_id.required_without' => 'Choose the room or the room type this calendar is for.',
        ]);
        if (! empty($data['room_id'])) {
            $data['room_type_id'] = null;
            $data['branch_id'] = (int) Room::whereKey($data['room_id'])->value('branch_id');
            $new = [(int) $data['room_id']];
        } else {
            $data['room_id'] = null;
            $data['branch_id'] = (int) RoomType::whereKey($data['room_type_id'])->value('branch_id');
            $new = Room::where('room_type_id', $data['room_type_id'])->pluck('id')->all();
        }
        $changesRooms = ! $feed || $feed->room_id !== $data['room_id'] || $feed->room_type_id !== $data['room_type_id'];
        if ($changesRooms && $this->limits->wouldExceed($user->hotel, $new)) {
            $this->refuseLimit($request);
        }
        if ($feed && blank($data['url'] ?? null)) {
            unset($data['url']); // keep the saved link
        }

        return $data;
    }

    private function refuseLimit(Request $request): never
    {
        $max = $this->limits->limit($request->user()->hotel);
        throw ValidationException::withMessages(['room_id' => PlanLimits::message($max === 0
            ? 'Calendar sync with Airbnb and Booking.com is not in your plan'
            : "Your plan has calendar sync for up to {$max} rooms")]);
    }

    private function find(Request $request, string $id): IcalFeed
    {
        return IcalFeed::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }

    private function room(Request $request, string $id): Room
    {
        return Room::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }
}

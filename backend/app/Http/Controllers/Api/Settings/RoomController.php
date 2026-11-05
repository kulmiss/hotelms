<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rooms: add one by one or with the room generator (floors × rooms per floor).
 * The plan's room limit counts all rooms across every branch.
 */
class RoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branches = $request->user()->accessibleBranches()->pluck('id');

        $rooms = Room::query()->with('roomType:id,name')
            ->whereIn('branch_id', $branches)
            ->when($request->input('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->orderBy('branch_id')->orderByRaw('LENGTH(number)')->orderBy('number')
            ->get();

        return response()->json(['data' => $rooms, 'limit' => $this->limitInfo($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'number' => ['required', 'string', 'max:20'],
            'floor' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $type = $this->allowedType($request, (int) $data['room_type_id']);
        $this->guardLimit($request, 1);
        $this->guardFree($type->branch_id, [$data['number']]);

        $room = Room::create($data + ['branch_id' => $type->branch_id, 'status' => 'available']);

        return response()->json(['data' => $room->load('roomType:id,name')], 201);
    }

    /**
     * Room generator: e.g. floors 1–4, 10 rooms each, numbered 101…410.
     * "types" maps a position on the floor (1-based) to a room type; others get the default type.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'first_floor' => ['required', 'integer', 'between:0,99'],
            'floors' => ['required', 'integer', 'between:1,50'],
            'rooms_per_floor' => ['required', 'integer', 'between:1,99'],
            'start_number' => ['required', 'integer', 'between:1,99'],
            'types' => ['nullable', 'array'],
            'types.*' => ['integer'],
            'preview' => ['sometimes', 'boolean'],
        ]);
        $default = $this->allowedType($request, (int) $data['room_type_id']);
        $types = collect($data['types'] ?? [])->map(fn ($id) => $this->allowedType($request, (int) $id));
        if ($types->contains(fn (RoomType $t) => $t->branch_id !== $default->branch_id)) {
            throw ValidationException::withMessages(['types' => 'All room types must be in the same branch.']);
        }

        $plan = [];
        for ($f = 0; $f < $data['floors']; $f++) {
            $floor = $data['first_floor'] + $f;
            for ($i = 0; $i < $data['rooms_per_floor']; $i++) {
                $position = $i + 1;
                $number = $floor === 0
                    ? sprintf('G%02d', $data['start_number'] + $i)
                    : $floor.str_pad((string) ($data['start_number'] + $i), 2, '0', STR_PAD_LEFT);
                $type = $types->get($position) ?? $default;
                $plan[] = ['number' => $number, 'floor' => (string) $floor, 'room_type_id' => $type->id, 'room_type' => $type->name];
            }
        }

        $existing = Room::where('branch_id', $default->branch_id)->whereIn('number', array_column($plan, 'number'))->pluck('number')->all();

        if ($request->boolean('preview')) {
            return response()->json(['data' => $plan, 'existing' => $existing, 'limit' => $this->limitInfo($request)]);
        }

        if ($existing) {
            throw ValidationException::withMessages(['first_floor' => 'These room numbers already exist: '.implode(', ', array_slice($existing, 0, 10))]);
        }
        $this->guardLimit($request, count($plan));

        DB::transaction(function () use ($plan, $default) {
            foreach ($plan as $p) {
                Room::create(['branch_id' => $default->branch_id, 'room_type_id' => $p['room_type_id'], 'number' => $p['number'], 'floor' => $p['floor'], 'status' => 'available']);
            }
        });

        return response()->json(['created' => count($plan), 'limit' => $this->limitInfo($request)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $room = $this->findRoom($request, $id);
        $data = $request->validate([
            'room_type_id' => ['required', 'integer'],
            'number' => ['required', 'string', 'max:20'],
            'floor' => ['nullable', 'string', 'max:10'],
            'status' => ['required', Rule::in(Room::STATUSES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $type = $this->allowedType($request, (int) $data['room_type_id']);
        if ($type->branch_id !== $room->branch_id) {
            throw ValidationException::withMessages(['room_type_id' => 'Choose a room type of the same branch.']);
        }
        if ($data['number'] !== $room->number) {
            $this->guardFree($room->branch_id, [$data['number']]);
        }

        $room->update($data);

        return response()->json(['data' => $room->fresh()->load('roomType:id,name')]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $room = $this->findRoom($request, $id);
        if (ReservationRoom::where('room_id', $room->id)->whereIn('status', Reservation::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['room' => 'This room has a guest or a booking. Move them to another room first.']);
        }
        $room->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ------------------------------------------------------------ helpers

    private function allowedType(Request $request, int $id): RoomType
    {
        $type = RoomType::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->find($id);
        if (! $type) {
            throw ValidationException::withMessages(['room_type_id' => 'Choose a room type.']);
        }

        return $type;
    }

    private function findRoom(Request $request, string $id): Room
    {
        return Room::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }

    /** @param  list<string>  $numbers */
    private function guardFree(int $branchId, array $numbers): void
    {
        if (Room::where('branch_id', $branchId)->whereIn('number', $numbers)->exists()) {
            throw ValidationException::withMessages(['number' => 'This room number already exists in the branch.']);
        }
    }

    private function guardLimit(Request $request, int $adding): void
    {
        $limit = $this->limitInfo($request);
        if ($limit['max'] !== null && $limit['max'] < $limit['used'] + $adding) {
            throw ValidationException::withMessages(['limit' => "Your plan allows {$limit['max']} rooms ({$limit['used']} used). Upgrade the plan to add more."]);
        }
    }

    /** @return array{used: int, max: int|null} */
    private function limitInfo(Request $request): array
    {
        return ['used' => Room::count(), 'max' => $request->user()->hotel->effectivePlan()?->max_rooms];
    }
}

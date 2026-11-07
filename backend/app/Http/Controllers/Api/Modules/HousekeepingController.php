<?php

namespace App\Http\Controllers\Api\Modules;

use App\Http\Controllers\Controller;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\User;
use App\Services\Modules\Housekeeping;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Housekeeping board, my tasks, assign tasks (screen-list.md section 8). */
class HousekeepingController extends Controller
{
    public function __construct(private Housekeeping $housekeeping) {}

    public function board(Request $request): JsonResponse
    {
        $branchIds = $this->branchIds($request);
        $this->housekeeping->ensureTasks($branchIds);
        $tasks = HousekeepingTask::with('assignee:id,name')->whereIn('branch_id', $branchIds)->where('status', '!=', 'done')->get()->groupBy('room_id');
        $rooms = Room::with('roomType:id,name')->whereIn('branch_id', $branchIds)->whereIn('status', ['dirty', 'cleaning', 'available', 'maintenance'])
            ->orderBy('floor')->orderByRaw('LENGTH(number)')->orderBy('number')->get()
            ->map(fn (Room $r) => [
                'id' => $r->id, 'number' => $r->number, 'floor' => $r->floor, 'status' => $r->status, 'room_type' => $r->roomType?->name,
                'tasks' => ($tasks->get($r->id) ?? collect())->map(fn ($t) => $this->task($t, $request))->values(),
            ]);

        return response()->json(['data' => $rooms]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = $request->boolean('mine') || ! $user->hasPermission('housekeeping.assign');
        $this->housekeeping->ensureTasks($this->branchIds($request));
        $tasks = HousekeepingTask::with(['room:id,number,floor,status', 'assignee:id,name'])->whereIn('branch_id', $this->branchIds($request))
            ->when($mine, fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhereNull('assigned_to')))
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)), fn ($q) => $q->where('status', '!=', 'done'))
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")->orderBy('due_at')->limit(200)->get();

        return response()->json(['data' => $tasks->map(fn ($t) => $this->task($t, $request))]);
    }

    /** People who can be given housekeeping tasks. */
    public function staff(Request $request): JsonResponse
    {
        $users = User::where('hotel_id', $request->user()->hotel_id)->where('status', 'active')->with('role')->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('housekeeping.own_tasks'))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values();

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $room = Room::whereIn('branch_id', $this->branchIds($request))->findOrFail($data['room_id']);
        $task = HousekeepingTask::create($data + ['branch_id' => $room->branch_id, 'status' => 'pending', 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->task($task->fresh(['room', 'assignee']), $request)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);
        $data = $this->validated($request);
        unset($data['room_id']);
        $task->update($data);

        return response()->json(['data' => $this->task($task->fresh(['room', 'assignee']), $request)]);
    }

    public function start(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->task($this->housekeeping->start($this->find($request, $id), $request->user()), $request)]);
    }

    public function done(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->task($this->housekeeping->done($this->find($request, $id), $request->user(), $data['notes'] ?? null), $request)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(HousekeepingTask::TYPES)],
            'priority' => ['required', Rule::in(HousekeepingTask::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['assigned_to']) && ! User::where('hotel_id', $request->user()->hotel_id)->whereKey($data['assigned_to'])->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose a staff member.']);
        }
        if (! empty($data['due_at'])) {
            $data['due_at'] = HotelClock::parse($data['due_at'], $request->user()->hotel);
        }

        return $data;
    }

    private function find(Request $request, string $id): HousekeepingTask
    {
        return HousekeepingTask::with('room')->whereIn('branch_id', $this->branchIds($request))->findOrFail($id);
    }

    /** @return list<int> */
    private function branchIds(Request $request): array
    {
        $ids = $request->user()->accessibleBranches()->pluck('id')->all();
        $b = $request->integer('branch_id');

        return $b && in_array($b, $ids, true) ? [$b] : $ids;
    }

    /** @return array<string, mixed> */
    private function task(HousekeepingTask $t, Request $request): array
    {
        $hotel = $request->user()->hotel;

        return [
            'id' => $t->id, 'type' => $t->type, 'priority' => $t->priority, 'status' => $t->status, 'notes' => $t->notes,
            'room' => $t->room ? ['id' => $t->room->id, 'number' => $t->room->number, 'floor' => $t->room->floor, 'status' => $t->room->status] : null,
            'assigned_to' => $t->assignee?->only(['id', 'name']),
            'due_at' => HotelClock::iso($t->due_at, $hotel), 'started_at' => HotelClock::iso($t->started_at, $hotel), 'completed_at' => HotelClock::iso($t->completed_at, $hotel),
        ];
    }
}

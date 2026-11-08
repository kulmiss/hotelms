<?php

namespace App\Services\Modules;

use App\Models\Hotel;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\HotelModules;
use Illuminate\Validation\ValidationException;

/**
 * Housekeeping (optional module): a cleaning task for every room after check-out;
 * start → room "cleaning", done → room "available" (workflows.md W6, W7).
 */
class Housekeeping
{
    public function __construct(private HotelModules $modules) {}

    /** The open (not done) task of this kind for a room, if any (owner, QA 8 Nov: never two at once). */
    public function openTask(int $roomId, string $type, ?int $except = null): ?HousekeepingTask
    {
        return HousekeepingTask::where('room_id', $roomId)->where('type', $type)->where('status', '!=', 'done')
            ->when($except, fn ($q) => $q->whereKeyNot($except))->first();
    }

    public function tasksForCheckOut(Reservation $reservation): void
    {
        if (! $this->modules->isEnabled($reservation->hotel, 'housekeeping')) {
            return;
        }
        foreach ($reservation->rooms()->whereNotNull('room_id')->get() as $rr) {
            $open = HousekeepingTask::where('room_id', $rr->room_id)->where('type', 'cleaning')->where('status', '!=', 'done')->first();
            if ($open && ! $open->reservation_id) {
                $open->update(['reservation_id' => $reservation->id]); // made when the room turned dirty
            }
            if (! $open) {
                HousekeepingTask::create([
                    'branch_id' => $reservation->branch_id, 'room_id' => $rr->room_id, 'reservation_id' => $reservation->id,
                    'type' => 'cleaning', 'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addHours(2),
                ]);
            }
        }
    }

    /**
     * Any room that becomes dirty (marked on the room board, a room move, …) gets a cleaning task,
     * so it shows on the housekeepers' list. Never two open tasks for one room.
     */
    public function taskForDirtyRoom(Room $room): void
    {
        $hotel = Hotel::find($room->hotel_id);
        if (! $hotel || ! $this->modules->isEnabled($hotel, 'housekeeping')) {
            return;
        }
        $open = HousekeepingTask::where('room_id', $room->id)->where('type', 'cleaning')->where('status', '!=', 'done')->exists();
        if (! $open) {
            HousekeepingTask::create([
                'hotel_id' => $room->hotel_id, 'branch_id' => $room->branch_id, 'room_id' => $room->id,
                'type' => 'cleaning', 'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addHours(2), 'created_by' => auth()->id(),
            ]);
        }
    }

    /**
     * Every dirty room has a cleaning task, also rooms made dirty in bulk (imports, the demo, older data):
     * otherwise the room waits on the board but never shows in anyone's list.
     *
     * @param  list<int>  $branchIds
     */
    public function ensureTasks(array $branchIds): void
    {
        Room::whereIn('branch_id', $branchIds)->where('status', 'dirty')
            ->whereDoesntHave('housekeepingTasks', fn ($q) => $q->where('type', 'cleaning')->where('status', '!=', 'done'))
            ->limit(200)->get()->each(fn (Room $room) => $this->taskForDirtyRoom($room));
    }

    public function closeCleaningTasks(Room $room): void
    {
        HousekeepingTask::where('room_id', $room->id)->where('type', 'cleaning')->where('status', '!=', 'done')
            // (No start time: it does not count in the "minutes per room" averages.)
            ->update(['status' => 'done', 'completed_at' => now(), 'completed_by' => auth()->id()]);
    }

    public function start(HousekeepingTask $task, User $user): HousekeepingTask
    {
        $this->assertMayWork($task, $user);
        if ($task->status === 'done') {
            throw ValidationException::withMessages(['status' => 'This task is done.']);
        }
        $task->update(['status' => 'in_progress', 'started_at' => $task->started_at ?? now(), 'assigned_to' => $task->assigned_to ?? $user->id]);
        if ($task->type === 'cleaning' && $task->room && in_array($task->room->status, ['dirty', 'available'], true)) {
            $task->room->update(['status' => 'cleaning']);
        }

        return $task->fresh('room');
    }

    public function done(HousekeepingTask $task, User $user, ?string $notes = null): HousekeepingTask
    {
        $this->assertMayWork($task, $user);
        $task->update([
            'status' => 'done', 'completed_at' => now(), 'completed_by' => $user->id, 'started_at' => $task->started_at ?? now(),
            'notes' => $notes !== null && $notes !== '' ? trim(($task->notes ? $task->notes."\n" : '').$notes) : $task->notes,
        ]);
        if (in_array($task->type, ['cleaning', 'inspection'], true) && $task->room && in_array($task->room->status, ['dirty', 'cleaning'], true)) {
            $task->room->update(['status' => 'available']);
        }

        return $task->fresh('room');
    }

    private function assertMayWork(HousekeepingTask $task, User $user): void
    {
        if ($user->hasPermission('housekeeping.assign')) {
            return;
        }
        if (! $user->hasPermission('housekeeping.own_tasks') || ($task->assigned_to !== null && $task->assigned_to !== $user->id)) {
            throw ValidationException::withMessages(['task' => 'This task belongs to someone else.']);
        }
    }
}

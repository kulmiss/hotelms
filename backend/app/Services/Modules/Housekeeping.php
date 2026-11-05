<?php

namespace App\Services\Modules;

use App\Models\HousekeepingTask;
use App\Models\Reservation;
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

    public function tasksForCheckOut(Reservation $reservation): void
    {
        if (! $this->modules->isEnabled($reservation->hotel, 'housekeeping')) {
            return;
        }
        foreach ($reservation->rooms()->whereNotNull('room_id')->get() as $rr) {
            $open = HousekeepingTask::where('room_id', $rr->room_id)->where('type', 'cleaning')->where('status', '!=', 'done')->exists();
            if (! $open) {
                HousekeepingTask::create([
                    'branch_id' => $reservation->branch_id, 'room_id' => $rr->room_id, 'reservation_id' => $reservation->id,
                    'type' => 'cleaning', 'priority' => 'normal', 'status' => 'pending', 'due_at' => now()->addHours(2),
                ]);
            }
        }
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

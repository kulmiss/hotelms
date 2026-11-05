<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The Hotel Admin's setup walkthrough (screen-list.md section 12).
 * Steps 1–5 and "review" are required; the others can be skipped
 * (the defaults stay and a "Finish setup" card remains on the dashboard).
 * Some steps are known from the data itself (password changed, rooms exist).
 */
class HotelSetup
{
    public const STEPS = ['account', 'profile', 'branches', 'room_types', 'rooms', 'payments', 'taxes', 'booking_rules', 'cancellation', 'modules', 'team', 'review'];

    public const REQUIRED = ['account', 'profile', 'branches', 'room_types', 'rooms', 'review'];

    /**
     * Call inside the hotel's tenant context.
     *
     * @return array{completed: bool, started: bool, steps: list<array{key: string, required: bool, state: string}>, skipped: int, remaining: int}
     */
    public function overview(Hotel $hotel, ?User $admin): array
    {
        $steps = array_map(fn (string $key) => [
            'key' => $key,
            'required' => in_array($key, self::REQUIRED, true),
            'state' => $this->state($hotel, $admin, $key),
        ], self::STEPS);

        return [
            'completed' => $hotel->setup_completed_at !== null,
            'started' => ! empty($hotel->setup_steps),
            'steps' => $steps,
            'skipped' => count(array_filter($steps, fn ($s) => $s['state'] === 'skipped')),
            'remaining' => count(array_filter($steps, fn ($s) => $s['state'] === 'todo')),
        ];
    }

    public function state(Hotel $hotel, ?User $admin, string $key): string
    {
        return match ($key) {
            'account' => $admin && ! $admin->must_change_password ? 'done' : 'todo',
            'room_types' => RoomType::exists() ? 'done' : 'todo',
            'rooms' => Room::exists() ? 'done' : 'todo',
            'review' => $hotel->setup_completed_at ? 'done' : 'todo',
            default => $hotel->setup_steps[$key] ?? 'todo',
        };
    }

    /** "Save and continue" (done) or "Skip for now" (skipped). */
    public function mark(Hotel $hotel, string $key, string $state): void
    {
        if (! in_array($key, self::STEPS, true) || $key === 'review') {
            throw ValidationException::withMessages(['step' => 'Unknown step.']);
        }
        if ($state === 'skipped' && in_array($key, self::REQUIRED, true)) {
            throw ValidationException::withMessages(['step' => 'This step is required.']);
        }
        if ($state === 'done' && $key === 'room_types' && ! RoomType::exists()) {
            throw ValidationException::withMessages(['step' => 'Add at least one room type.']);
        }
        if ($state === 'done' && $key === 'rooms' && ! Room::exists()) {
            throw ValidationException::withMessages(['step' => 'Add at least one room.']);
        }

        $hotel->forceFill(['setup_steps' => array_merge($hotel->setup_steps ?? [], [$key => $state])])->save();
    }

    /** Review and finish: every required step must be done; untouched optional steps count as skipped. */
    public function finish(Hotel $hotel, User $admin): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED,
            fn ($key) => $key !== 'review' && $this->state($hotel, $admin, $key) !== 'done',
        ));
        if ($missing) {
            throw ValidationException::withMessages(['step' => 'Finish these steps first: '.implode(', ', $missing)]);
        }

        $steps = $hotel->setup_steps ?? [];
        foreach (array_diff(self::STEPS, self::REQUIRED) as $key) {
            $steps[$key] ??= 'skipped';
        }
        $hotel->forceFill(['setup_steps' => $steps, 'setup_completed_at' => now()])->save();
    }
}

<?php

namespace App\Services\Modules;

use App\Models\Attendance as AttendanceRecord;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\HotelSettings;
use App\Support\HotelClock;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Attendance (optional module): clock in/out by the person (self), on a shared kiosk with a PIN,
 * or entered by a manager from a paper register. Late = more than 10 minutes after the shift start.
 */
class Attendance
{
    public const LATE_AFTER_MINUTES = 10;

    public function __construct(private HotelSettings $settings) {}

    public function methodAllowed(string $method): bool
    {
        return in_array($method, (array) $this->settings->group(HotelSettings::ATTENDANCE_DEFAULTS)['attendance_methods'], true);
    }

    /** Keyed hash of a PIN: the same PIN always gives the same value, so the kiosk can look it up. */
    public static function pinHash(string $pin, int $hotelId): string
    {
        return hash_hmac('sha256', $hotelId.'|'.$pin, (string) config('app.key'));
    }

    public function setPin(User $user, string $pin): void
    {
        if (self::isWeak($pin)) {
            throw ValidationException::withMessages(['pin' => 'This PIN is too easy to guess (like 1111 or 1234). Choose another.']);
        }
        $hash = self::pinHash($pin, (int) $user->hotel_id);
        if (User::where('hotel_id', $user->hotel_id)->where('attendance_pin', $hash)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['pin' => 'Someone else uses this PIN. Choose another.']);
        }
        $user->forceFill(['attendance_pin' => $hash])->save();
    }

    /** Same digit repeated, or a straight run up or down (1234, 9876). */
    public static function isWeak(string $pin): bool
    {
        $d = array_map('intval', str_split($pin));
        $same = count(array_unique($d)) === 1;
        $up = $down = true;
        for ($i = 1; $i < count($d); $i++) {
            $up = $up && $d[$i] === ($d[$i - 1] + 1) % 10;
            $down = $down && $d[$i] === ($d[$i - 1] + 9) % 10;
        }

        return $same || $up || $down;
    }

    public function findByPin(int $hotelId, string $pin): ?User
    {
        return User::where('hotel_id', $hotelId)->where('status', 'active')->where('attendance_pin', self::pinHash($pin, $hotelId))->first();
    }

    /** Clock in, or out if already in. */
    public function clock(User $user, string $method, ?int $branchId = null): AttendanceRecord
    {
        if (! $this->methodAllowed($method)) {
            throw ValidationException::withMessages(['method' => 'This way of clocking in is switched off for your hotel.']);
        }
        $hotel = $user->hotel;
        $today = HotelClock::now($hotel)->toDateString();
        $open = AttendanceRecord::where('user_id', $user->id)->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->where('clock_in_at', '>=', now()->subHours(20))->latest('clock_in_at')->first();
        if ($open) {
            $open->update(['clock_out_at' => now()]);

            return $open->fresh();
        }

        $assignment = ShiftAssignment::with('shift')->where('user_id', $user->id)->where('date', $today)->where('status', '!=', 'cancelled')->first();
        $branch = $branchId ?? $assignment?->branch_id ?? $user->accessibleBranches()->first()?->id;
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'You are not linked to a branch.']);
        }

        return AttendanceRecord::create([
            'branch_id' => $branch, 'user_id' => $user->id, 'shift_assignment_id' => $assignment?->id, 'date' => $today,
            'clock_in_at' => now(), 'method' => $method, 'status' => $this->lateness($assignment, now(), $hotel) ? 'late' : 'present',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function manual(array $data, User $manager): AttendanceRecord
    {
        if (! $this->methodAllowed('manual')) {
            throw ValidationException::withMessages(['method' => 'Manual entry is switched off for your hotel.']);
        }
        $hotel = $manager->hotel;
        $staff = User::where('hotel_id', $manager->hotel_id)->find($data['user_id']);
        if (! $staff) {
            throw ValidationException::withMessages(['user_id' => 'Choose a staff member.']);
        }
        $in = ! empty($data['clock_in']) ? HotelClock::parse($data['date'].'T'.$data['clock_in'], $hotel) : null;
        $out = ! empty($data['clock_out']) ? HotelClock::parse($data['date'].'T'.$data['clock_out'], $hotel) : null;
        if ($in && $out && $out->lte($in)) {
            $out->addDay(); // night shift
        }
        $assignment = ShiftAssignment::with('shift')->where('user_id', $staff->id)->where('date', $data['date'])->first();
        $status = $data['status'] ?? ($in && $this->lateness($assignment, $in, $hotel) ? 'late' : 'present');

        return AttendanceRecord::create([
            'branch_id' => $data['branch_id'] ?? $assignment?->branch_id ?? $staff->accessibleBranches()->first()?->id,
            'user_id' => $staff->id, 'shift_assignment_id' => $assignment?->id, 'date' => $data['date'],
            'clock_in_at' => $in, 'clock_out_at' => $out, 'method' => 'manual', 'recorded_by' => $manager->id,
            'status' => $status, 'notes' => $data['notes'] ?? null,
        ]);
    }

    private function lateness(?ShiftAssignment $assignment, Carbon $at, $hotel): bool
    {
        if (! $assignment?->shift) {
            return false;
        }
        $start = Carbon::parse($assignment->date->toDateString().' '.$assignment->shift->start_time, HotelClock::zone($hotel));

        return $at->gt($start->addMinutes(self::LATE_AFTER_MINUTES));
    }
}

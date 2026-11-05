<?php

namespace App\Http\Controllers\Api\Modules;

use App\Http\Controllers\Controller;
use App\Models\Attendance as AttendanceRecord;
use App\Models\User;
use App\Services\HotelSettings;
use App\Services\Modules\Attendance;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Clock in / out (self, kiosk PIN, manual) and the attendance report (screen-list.md 9.6–9.9). */
class AttendanceController extends Controller
{
    public function __construct(private Attendance $attendance) {}

    /** Am I clocked in? Which methods does the hotel allow? */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $open = AttendanceRecord::where('user_id', $user->id)->whereNull('clock_out_at')->whereNotNull('clock_in_at')->where('clock_in_at', '>=', now()->subHours(20))->latest('clock_in_at')->first();

        return response()->json([
            'clocked_in_at' => HotelClock::iso($open?->clock_in_at, $user->hotel),
            'methods' => app(HotelSettings::class)->group(HotelSettings::ATTENDANCE_DEFAULTS)['attendance_methods'],
            'has_pin' => $user->attendance_pin !== null,
        ]);
    }

    public function clock(Request $request): JsonResponse
    {
        $record = $this->attendance->clock($request->user(), 'self');

        return response()->json(['data' => $this->row($record, $request)]);
    }

    /** Shared kiosk: whoever types their PIN is clocked in or out. */
    public function kiosk(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,6']]);
        $hotelId = (int) $request->user()->hotel_id;
        $key = 'kiosk:'.$hotelId.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['pin' => 'Too many wrong PINs. Wait a minute.']);
        }
        $staff = $this->attendance->findByPin($hotelId, $data['pin']);
        if (! $staff) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['pin' => 'Unknown PIN.']);
        }
        RateLimiter::clear($key);
        $record = $this->attendance->clock($staff, 'kiosk_pin');

        return response()->json(['data' => $this->row($record, $request), 'name' => $staff->name, 'action' => $record->clock_out_at ? 'out' : 'in']);
    }

    /** Set a staff member's kiosk PIN (staff.manage) or your own. */
    public function setPin(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,6']]);
        $me = $request->user();
        $staff = User::where('hotel_id', $me->hotel_id)->findOrFail($userId);
        abort_unless($staff->id === $me->id || $me->hasPermission('staff.manage'), 403, 'You may not do this.');
        $this->attendance->setPin($staff, $data['pin']);

        return response()->json(['message' => 'PIN saved.']);
    }

    public function manual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', Rule::in(['present', 'late', 'absent', 'leave'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->row($this->attendance->manual($data, $request->user()), $request)], 201);
    }

    /** Rows plus a summary per person (days present, late, absent, hours). */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'user_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $rows = AttendanceRecord::with(['user:id,name'])->whereIn('branch_id', $user->accessibleBranches()->pluck('id'))
            ->whereBetween('date', [$data['from'], $data['to']])
            ->when($data['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->orderBy('date')->orderBy('clock_in_at')->get();

        $summary = $rows->groupBy('user_id')->map(function ($list) {
            $minutes = $list->sum(fn ($r) => $r->clock_in_at && $r->clock_out_at ? $r->clock_in_at->diffInMinutes($r->clock_out_at) : 0);

            return [
                'user' => $list->first()->user?->only(['id', 'name']),
                'present' => $list->whereIn('status', ['present', 'late'])->count(),
                'late' => $list->where('status', 'late')->count(),
                'absent' => $list->where('status', 'absent')->count(),
                'leave' => $list->where('status', 'leave')->count(),
                'hours' => round($minutes / 60, 1),
            ];
        })->values();

        return response()->json(['rows' => $rows->map(fn ($r) => $this->row($r, $request)), 'summary' => $summary]);
    }

    /** @return array<string, mixed> */
    private function row(AttendanceRecord $r, Request $request): array
    {
        $hotel = $request->user()->hotel;
        $r->loadMissing('user:id,name');

        return [
            'id' => $r->id, 'user' => $r->user?->only(['id', 'name']), 'date' => $r->date->toDateString(), 'method' => $r->method, 'status' => $r->status, 'notes' => $r->notes,
            'clock_in_at' => HotelClock::iso($r->clock_in_at, $hotel), 'clock_out_at' => HotelClock::iso($r->clock_out_at, $hotel),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DataAccessLog;
use App\Models\Hotel;
use App\Models\HotelNote;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin hotel page tabs (super-admin.md 2b): overview (plan usage), live data (always logged
 * with a reason, also in the hotel's own activity log), staff and devices, activity and notes.
 */
class HotelInsightController extends Controller
{
    public function __construct(private ActivityLogger $log, private TenantContext $tenant) {}

    /** Plan usage, staff and devices, notes, who looked at the data. No hotel guest data here. */
    public function overview(Hotel $hotel): JsonResponse
    {
        $rooms = DB::table('rooms')->where('hotel_id', $hotel->id)->whereNull('deleted_at')->count();
        $branches = DB::table('branches')->where('hotel_id', $hotel->id)->whereNull('deleted_at')->count();
        $staff = User::where('hotel_id', $hotel->id)->where('status', 'active')->count();
        $staffList = $this->tenant->acrossAllHotels(fn () => User::where('hotel_id', $hotel->id)->with('role:id,name')->orderBy('name')->get(['id', 'name', 'email', 'status', 'last_login_at', 'role_id', 'two_factor_confirmed_at']));
        $devices = DB::table('user_devices')->join('users', 'users.id', '=', 'user_devices.user_id')->where('user_devices.hotel_id', $hotel->id)
            ->orderByDesc('user_devices.last_seen_at')->limit(50)
            ->get(['user_devices.id', 'user_devices.name', 'user_devices.status', 'user_devices.last_seen_at', 'user_devices.ip', 'users.name as user']);

        return response()->json(['data' => [
            'usage' => [
                'rooms' => ['used' => $rooms, 'limit' => $hotel->limit('rooms')],
                'branches' => ['used' => $branches, 'limit' => $hotel->limit('branches')],
                'staff' => ['used' => $staff, 'limit' => $hotel->limit('staff')],
            ],
            'staff' => $staffList->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status, 'role' => $u->role?->name,
                'last_login_at' => $u->last_login_at?->toIso8601String(), 'two_factor' => $u->two_factor_confirmed_at !== null,
            ])->values(),
            'devices' => $devices->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'user' => $d->user, 'status' => $d->status, 'ip' => $d->ip, 'last_seen_at' => $d->last_seen_at ? Carbon::parse($d->last_seen_at)->toIso8601String() : null])->values(),
            'notes' => HotelNote::where('hotel_id', $hotel->id)->with('user:id,name')->latest('id')->limit(100)->get()
                ->map(fn (HotelNote $n) => ['id' => $n->id, 'body' => $n->body, 'channel' => $n->channel, 'author' => $n->user?->name, 'created_at' => $n->created_at?->toIso8601String()])->values(),
            'access' => DataAccessLog::where('hotel_id', $hotel->id)->with('user:id,name')->latest('id')->limit(30)->get()
                ->map(fn (DataAccessLog $a) => ['id' => $a->id, 'what' => $a->what, 'reason' => $a->reason, 'by' => $a->user?->name, 'at' => $a->created_at?->toIso8601String()])->values(),
        ]]);
    }

    /** Today's numbers and the hotel's latest activity. Needs a reason; logged once per half hour. */
    public function live(Request $request, Hotel $hotel): JsonResponse
    {
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']])['reason']);
        $user = $request->user();
        $recent = DataAccessLog::where('hotel_id', $hotel->id)->where('user_id', $user->id)->where('what', 'live_data')->where('created_at', '>=', now()->subMinutes(30))->exists();
        if (! $recent) {
            DataAccessLog::create(['user_id' => $user->id, 'hotel_id' => $hotel->id, 'what' => 'live_data', 'reason' => $reason, 'created_at' => now()]);
            $props = ['reason' => $reason, 'by' => $user->name];
            $this->log->log('support.live_data', "Looked at {$hotel->name}'s live data", null, $props + ['hotel' => $hotel->name]);
            $this->log->log('support.live_data', "HotelMS ({$user->name}) looked at today's data: {$reason}", $hotel, $props);
        }

        $tz = $hotel->timezone ?: 'Africa/Addis_Ababa';
        $start = now($tz)->startOfDay()->utc();
        $end = now($tz)->endOfDay()->utc();
        $rr = fn () => DB::table('reservation_rooms')->where('hotel_id', $hotel->id);
        $rooms = DB::table('rooms')->where('hotel_id', $hotel->id)->whereNull('deleted_at')->count();
        $occupied = $rr()->where('status', 'checked_in')->whereNotNull('room_id')->distinct()->count('room_id');
        $payments = DB::table('payments')->where('hotel_id', $hotel->id)->whereBetween('paid_at', [$start, $end])
            ->selectRaw("currency, SUM(CASE WHEN type = 'refund' THEN -amount ELSE amount END) as total")->groupBy('currency')->pluck('total', 'currency')
            ->map(fn ($v) => Money::round((string) $v));

        $activity = $this->tenant->acrossAllHotels(fn () => ActivityLog::where('hotel_id', $hotel->id)->with('user:id,name')->latest('id')->limit(30)->get());

        return response()->json(['data' => [
            'today' => [
                'rooms' => $rooms,
                'occupied' => $occupied,
                'occupancy' => $rooms ? round($occupied * 100 / $rooms) : 0,
                'arrivals' => $rr()->whereIn('status', ['pending', 'confirmed'])->whereBetween('check_in_at', [$start, $end])->count(),
                'departures' => $rr()->where('status', 'checked_in')->whereBetween('check_out_at', [$start, $end])->count(),
                'bookings_made' => DB::table('reservations')->where('hotel_id', $hotel->id)->whereBetween('created_at', [$start, $end])->count(),
                'payments' => $payments,
                'staff_online' => DB::table('user_devices')->where('hotel_id', $hotel->id)->where('status', 'active')->where('last_seen_at', '>=', now()->subMinutes(15))->distinct()->count('user_id'),
            ],
            'activity' => $activity->map(fn (ActivityLog $a) => ['id' => $a->id, 'event' => $a->event, 'description' => $a->description, 'user' => $a->user?->name, 'at' => $a->created_at?->toIso8601String()])->values(),
        ]]);
    }

    public function storeNote(Request $request, Hotel $hotel): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'channel' => ['nullable', 'in:phone,visit,telegram,email,other']]);
        $note = HotelNote::create($data + ['hotel_id' => $hotel->id, 'user_id' => $request->user()->id]);

        return response()->json(['data' => ['id' => $note->id]], 201);
    }

    public function destroyNote(Request $request, Hotel $hotel, string $id): JsonResponse
    {
        $note = HotelNote::where('hotel_id', $hotel->id)->findOrFail($id);
        // Only the author or the owner removes a note.
        abort_unless($note->user_id === $request->user()->id || $request->user()->platform_role === 'owner', 403, 'Only the person who wrote it can remove this note.');
        $note->delete();

        return response()->json(['message' => 'Removed.']);
    }
}

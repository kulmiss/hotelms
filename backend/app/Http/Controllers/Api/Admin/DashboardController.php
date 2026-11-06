<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Hotel;
use App\Models\SubscriptionPayment;
use App\Models\SupportRequest;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Super Admin command center (area 1): KPIs, needs attention, live activity, top hotels today. Demo hotels are left out. */
class DashboardController extends Controller
{
    public function __invoke(TenantContext $tenant): JsonResponse
    {
        $counts = Hotel::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $real = fn () => DB::table('hotels')->where('is_demo', false)->whereNull('deleted_at')->pluck('id');
        $ids = $real();
        // "Today" for the platform is Addis Ababa time (all hotels are in Ethiopia).
        $start = now('Africa/Addis_Ababa')->startOfDay()->utc();
        $end = now('Africa/Addis_Ababa')->endOfDay()->utc();

        $rooms = DB::table('rooms')->whereIn('hotel_id', $ids)->whereNull('deleted_at')->count();
        $occupied = DB::table('reservation_rooms')->whereIn('hotel_id', $ids)->where('status', 'checked_in')->whereNotNull('room_id')->distinct()->count('room_id');
        $money = DB::table('payments')->whereIn('hotel_id', $ids)->whereBetween('paid_at', [$start, $end])
            ->selectRaw("currency, SUM(CASE WHEN type = 'refund' THEN -amount ELSE amount END) as total")->groupBy('currency')->pluck('total', 'currency')
            ->map(fn ($v) => Money::round((string) $v));
        $top = DB::table('payments')->join('hotels', 'hotels.id', '=', 'payments.hotel_id')->whereIn('payments.hotel_id', $ids)
            ->whereBetween('payments.paid_at', [$start, $end])->where('payments.type', '!=', 'refund')
            ->selectRaw('hotels.id, hotels.name, payments.currency, SUM(payments.amount) as total, COUNT(*) as payments')
            ->groupBy('hotels.id', 'hotels.name', 'payments.currency')->orderByDesc('total')->limit(5)->get();
        $activity = $tenant->acrossAllHotels(fn () => ActivityLog::whereIn('hotel_id', $ids)->with(['user:id,name', 'hotel:id,name'])->latest('id')->limit(15)->get());

        return response()->json([
            'hotels' => [
                'total' => (int) $counts->sum(),
                'by_status' => collect(Hotel::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            ],
            'income_this_month' => SubscriptionPayment::query()
                ->where('paid_at', '>=', now()->startOfMonth())
                ->selectRaw('currency, sum(amount) as total')
                ->groupBy('currency')
                ->pluck('total', 'currency'),
            'today' => [
                'bookings' => DB::table('reservations')->whereIn('hotel_id', $ids)->whereBetween('created_at', [$start, $end])->count(),
                'money' => $money,
                'occupancy' => $rooms ? (int) round($occupied * 100 / $rooms) : 0,
                'occupied' => $occupied,
                'rooms' => $rooms,
                'staff_online' => DB::table('user_devices')->whereIn('hotel_id', $ids)->where('status', 'active')->where('last_seen_at', '>=', now()->subMinutes(15))->distinct()->count('user_id'),
                'open_support' => SupportRequest::withoutGlobalScope('hotel')->where('status', 'open')->count(),
            ],
            'trials_ending_soon' => Hotel::query()->where('status', 'trial')
                ->whereBetween('trial_ends_at', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->orderBy('trial_ends_at')->get(['id', 'name', 'trial_ends_at']),
            'needs_attention' => Hotel::query()->whereIn('status', ['grace', 'suspended'])
                ->orderBy('name')->limit(10)->get(['id', 'name', 'status', 'subscription_ends_at', 'trial_ends_at']),
            'top_hotels' => $top->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'currency' => $r->currency, 'total' => Money::round((string) $r->total), 'payments' => (int) $r->payments])->values(),
            'activity' => $activity->map(fn (ActivityLog $a) => ['id' => $a->id, 'description' => $a->description, 'user' => $a->user?->name, 'hotel' => $a->hotel ? ['id' => $a->hotel->id, 'name' => $a->hotel->name] : null, 'at' => $a->created_at?->toIso8601String()])->values(),
        ]);
    }
}

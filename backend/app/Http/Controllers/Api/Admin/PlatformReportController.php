<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\HotelSetup;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Super Admin platform reports and hotel health (reports.md feature 18). */
class PlatformReportController extends Controller
{
    public function platform(): JsonResponse
    {
        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'));
        // Left join: a payment whose plan was deleted still counts. Totals are kept per currency (never mixed).
        $income = SubscriptionPayment::query()->leftJoin('plans', 'plans.id', '=', 'subscription_payments.plan_id')
            ->where('subscription_payments.paid_at', '>=', now()->startOfMonth()->subMonths(11))
            ->selectRaw("DATE_FORMAT(subscription_payments.paid_at, '%Y-%m') as m, COALESCE(plans.name, '—') as plan, subscription_payments.currency as currency, SUM(subscription_payments.amount) as total, COUNT(*) as payments")
            ->groupBy('m', 'plan', 'currency')->get();
        $hotels = Hotel::where('is_demo', false);

        return response()->json(['data' => [
            'income' => $months->map(fn ($m) => [
                'month' => $m,
                'totals' => $income->where('m', $m)->groupBy('currency')->map(fn ($rows) => Money::sum($rows->pluck('total')->map(fn ($v) => (string) $v)->all())),
                'by_plan' => $income->where('m', $m)->map(fn ($r) => ['plan' => $r->plan, 'currency' => $r->currency, 'total' => Money::round((string) $r->total), 'payments' => (int) $r->payments])->values(),
            ])->values(),
            'hotels' => (clone $hotels)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
            'trials_ending' => (clone $hotels)->where('status', 'trial')->whereBetween('trial_ends_at', [now()->toDateString(), now()->addDays(14)->toDateString()])
                ->orderBy('trial_ends_at')->get(['id', 'name', 'trial_ends_at']),
            'lost' => (clone $hotels)->where(fn ($q) => $q->where('status', 'suspended')->orWhereNotNull('deleted_at'))->withTrashed()->count(),
            'new_this_month' => (clone $hotels)->where('created_at', '>=', now()->startOfMonth())->count(),
            'rooms' => DB::table('rooms')->join('hotels', 'hotels.id', '=', 'rooms.hotel_id')->where('hotels.is_demo', false)->whereNull('rooms.deleted_at')->count(),
        ]]);
    }

    /**
     * healthy: set up, used in the last 3 days, paying · watch: quiet for a week or in grace
     * at risk: suspended or quiet for 14+ days · stuck: setup not finished after 7 days.
     */
    public function health(TenantContext $tenant, HotelSetup $setup): JsonResponse
    {
        $lastActivity = DB::table('activity_logs')->whereNotNull('hotel_id')->selectRaw('hotel_id, MAX(created_at) as at')->groupBy('hotel_id')->pluck('at', 'hotel_id');
        $bookings = DB::table('reservations')->where('created_at', '>=', now()->subDays(30))->selectRaw('hotel_id, COUNT(*) as c')->groupBy('hotel_id')->pluck('c', 'hotel_id');

        $rows = Hotel::where('is_demo', false)->with('plan:id,name')->orderBy('name')->get()->map(function (Hotel $h) use ($lastActivity, $bookings, $tenant, $setup) {
            $o = $tenant->runAs($h, fn () => $setup->overview($h, User::where('hotel_id', $h->id)->whereHas('role', fn ($q) => $q->where('key', 'hotel_admin'))->orderBy('id')->first()));
            $done = collect($o['steps'])->where('state', '!=', 'todo')->count();
            $percent = (int) round($done / max(1, count($o['steps'])) * 100);
            $last = isset($lastActivity[$h->id]) ? Carbon::parse($lastActivity[$h->id]) : null;
            $quietDays = $last ? (int) $last->diffInDays(now()) : null;

            $state = match (true) {
                ! $o['completed'] && $h->created_at->lt(now()->subDays(7)) => 'stuck',
                $h->status === 'suspended' || $quietDays === null || $quietDays >= 14 => 'at_risk',
                $h->status === 'grace' || $quietDays >= 7 || (int) ($bookings[$h->id] ?? 0) === 0 => 'watch',
                default => 'healthy',
            };

            return [
                'id' => $h->id, 'name' => $h->name, 'status' => $h->status, 'plan' => $h->plan?->name,
                'setup_percent' => $percent, 'setup_completed' => $o['completed'],
                'last_activity' => $last?->toIso8601String(), 'quiet_days' => $quietDays,
                'bookings_30_days' => (int) ($bookings[$h->id] ?? 0),
                'subscription_ends_at' => $h->subscription_ends_at?->toDateString(),
                'health' => $state,
            ];
        });

        return response()->json(['data' => $rows->values(), 'counts' => $rows->countBy('health')]);
    }
}

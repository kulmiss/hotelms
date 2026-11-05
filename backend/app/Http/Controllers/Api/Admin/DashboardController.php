<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /** First numbers for the command center (more arrive with each phase). */
    public function __invoke(): JsonResponse
    {
        $counts = Hotel::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

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
            'trials_ending_soon' => Hotel::query()->where('status', 'trial')
                ->whereBetween('trial_ends_at', [now()->toDateString(), now()->addDays(7)->toDateString()])
                ->orderBy('trial_ends_at')->get(['id', 'name', 'trial_ends_at']),
            'needs_attention' => Hotel::query()->whereIn('status', ['grace', 'suspended'])
                ->orderBy('name')->limit(10)->get(['id', 'name', 'status', 'subscription_ends_at', 'trial_ends_at']),
        ]);
    }
}

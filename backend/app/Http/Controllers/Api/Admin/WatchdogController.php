<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WatchdogFinding;
use App\Services\ActivityLogger;
use App\Services\Watchdog\Watchdog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin → Watchdog: what looks wrong, where, and what to do. */
class WatchdogController extends Controller
{
    private const ORDER = "CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END";

    public function index(Request $request): JsonResponse
    {
        $show = $request->validate(['show' => ['nullable', 'in:open,fixed']])['show'] ?? 'open';
        $rows = WatchdogFinding::with(['hotel:id,name', 'resolver:id,name'])
            ->when($show === 'open', fn ($q) => $q->whereNull('resolved_at'), fn ($q) => $q->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(30)))
            ->orderByRaw(self::ORDER)->orderByDesc('last_seen_at')->limit(200)->get();
        $open = WatchdogFinding::whereNull('resolved_at')->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '<', now()));

        return response()->json([
            'data' => $rows->map(fn (WatchdogFinding $f) => [
                'id' => $f->id, 'check' => $f->check, 'severity' => $f->severity, 'title' => $f->title, 'details' => $f->details ?? [], 'advice' => $f->advice, 'link' => $f->link,
                'hotel' => $f->hotel ? ['id' => $f->hotel->id, 'name' => $f->hotel->name] : null, 'times' => $f->times,
                'first_seen_at' => $f->first_seen_at?->toIso8601String(), 'last_seen_at' => $f->last_seen_at?->toIso8601String(),
                'resolved_at' => $f->resolved_at?->toIso8601String(), 'resolved_by' => $f->resolver?->name ?? ($f->resolved_at ? 'closed by itself' : null),
                'muted_until' => $f->muted_until?->toIso8601String(),
            ])->values(),
            'counts' => [
                'critical' => (clone $open)->where('severity', 'critical')->count(),
                'warning' => (clone $open)->where('severity', 'warning')->count(),
                'info' => (clone $open)->where('severity', 'info')->count(),
            ],
            'last_run' => WatchdogFinding::max('last_seen_at'),
        ]);
    }

    public function run(Watchdog $watchdog): JsonResponse
    {
        $open = $watchdog->run(true);

        return response()->json(['message' => 'Checked.', 'open' => $open->count()]);
    }

    public function resolve(Request $request, WatchdogFinding $finding, ActivityLogger $log): JsonResponse
    {
        $finding->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id]);
        $log->log('watchdog.resolved', "Watchdog finding marked fixed: {$finding->title}", $finding);

        return response()->json(['message' => 'Marked as fixed.']);
    }

    public function mute(Request $request, WatchdogFinding $finding, ActivityLogger $log): JsonResponse
    {
        $days = (int) $request->validate(['days' => ['required', 'integer', 'in:1,7,30']])['days'];
        $finding->update(['muted_until' => now()->addDays($days)]);
        $log->log('watchdog.muted', "Watchdog finding muted for {$days} days: {$finding->title}", $finding);

        return response()->json(['message' => 'Muted.']);
    }
}

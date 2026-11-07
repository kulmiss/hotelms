<?php

namespace App\Http\Controllers\Api\Hotel;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\HotelClock;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ActivityLogController extends Controller
{
    /** The hotel's activity log (tenant-scoped), newest first. */
    public function index(Request $request): JsonResponse|Response
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'user_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $hotel = $request->user()->hotel;

        $query = ActivityLog::query()
            ->with('user:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where('description', 'like', "%{$s}%"))
            ->when($request->input('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->input('from') && $hotel, fn ($q) => $q->where('created_at', '>=', HotelClock::parse($request->input('from'), $hotel)))
            ->when($request->input('to') && $hotel, fn ($q) => $q->where('created_at', '<', HotelClock::parse($request->input('to'), $hotel)->addDay()));
        ListQuery::sort($query, $request, ['when' => 'id', 'who' => 'user_id', 'action' => 'action'], 'when');
        if (ListQuery::wantsFile($request)) {
            return ListQuery::file($request, $query, 'Activity', ['When', 'Who', 'Action', 'What'], fn (ActivityLog $a) => [
                $hotel ? HotelClock::local($a->created_at, $hotel)->format('Y-m-d H:i') : (string) $a->created_at, $a->user?->name, $a->action, $a->description,
            ], 'activity_log.view');
        }
        $page = $query->paginate(ListQuery::perPage($request, 50));

        return response()->json($page);
    }
}

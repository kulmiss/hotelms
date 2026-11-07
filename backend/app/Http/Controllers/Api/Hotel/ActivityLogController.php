<?php

namespace App\Http\Controllers\Api\Hotel;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /** The hotel's activity log (tenant-scoped), newest first. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'user_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $hotel = $request->user()->hotel;

        $page = ActivityLog::query()
            ->with('user:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where('description', 'like', "%{$s}%"))
            ->when($request->input('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->input('from') && $hotel, fn ($q) => $q->where('created_at', '>=', HotelClock::parse($request->input('from'), $hotel)))
            ->when($request->input('to') && $hotel, fn ($q) => $q->where('created_at', '<', HotelClock::parse($request->input('to'), $hotel)->addDay()))
            ->latest('id')
            ->paginate(max(1, min((int) $request->input('per_page', 50), 100)));

        return response()->json($page);
    }
}

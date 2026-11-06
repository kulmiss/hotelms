<?php

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The bell: your own in-app notifications (always on – automation.md). */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = AppNotification::where('user_id', $user->id)->latest('id')->limit(50)->get();

        return response()->json([
            'data' => $rows->map(fn (AppNotification $n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link, 'urgent' => $n->urgent,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'unread' => AppNotification::where('user_id', $user->id)->whereNull('read_at')->count(),
        ]);
    }

    /** Marks some (ids) or all of your notifications as read. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:200'], 'ids.*' => ['integer']]);
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Done.']);
    }
}

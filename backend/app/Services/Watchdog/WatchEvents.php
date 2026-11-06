<?php

namespace App\Services\Watchdog;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Notes small things while the system runs, for the watchdog: refused requests (403/404), failed sign-ins
 * and server errors. Cheap (one insert), capped per person per minute, and never breaks a request.
 */
class WatchEvents
{
    public const PER_MINUTE = 120;

    public function record(string $kind, ?Request $request = null, ?int $status = null, ?string $message = null): void
    {
        try {
            $user = $request?->user();
            $who = $user?->id ? 'u'.$user->id : 'ip'.($request?->ip() ?? '-');
            $key = "watch:{$who}:".now()->format('YmdHi');
            Cache::add($key, 0, 120);
            if (Cache::increment($key) > self::PER_MINUTE) {
                return; // enough to see the pattern
            }
            DB::table('watch_events')->insert([
                'kind' => $kind,
                'user_id' => $user?->id,
                'hotel_id' => $user?->hotel_id,
                'ip' => $request?->ip(),
                'method' => $request?->method(),
                'path' => $request ? mb_substr('/'.ltrim($request->path(), '/'), 0, 200) : null,
                'status' => $status,
                'message' => $message !== null ? mb_substr($message, 0, 500) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // the watchdog must never break the app
        }
    }
}

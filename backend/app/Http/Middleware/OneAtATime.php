<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Changes to one booking (or one bill) run one at a time, so a double tap or two
 * receptionists at once cannot check out twice, refund twice or cancel twice.
 * The second request waits, then sees the new state and is refused by the normal rules.
 * Works with every cache driver (database on shared hosting, Redis on a VPS).
 */
class OneAtATime
{
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $id = (string) $request->route('id');
        $hotel = (string) ($request->user()?->hotel_id ?? 0);
        if ($id === '') {
            return $next($request);
        }

        // A bill shares the lock of its booking: paying a bill and checking out never overlap.
        if ($kind === 'invoice') {
            $stay = DB::table('invoices')->where('hotel_id', $hotel)->where('id', (int) $id)->value('reservation_id');
            [$kind, $id] = $stay ? ['reservation', (string) $stay] : ['invoice', $id];
        }

        // Our own wait loop on the real clock (Laravel's block() follows the app clock, which tests freeze).
        $lock = Cache::lock("one-at-a-time:{$kind}:{$hotel}:{$id}", 30);
        $until = microtime(true) + max(0, (int) config('hotelms.lock_wait_seconds'));
        while (! $lock->get()) {
            if (microtime(true) >= $until) {
                return response()->json(['message' => 'Someone is changing this right now. Please try again in a moment.', 'code' => 'busy'], 409);
            }
            usleep(150_000);
        }

        try {
            return $next($request);
        } finally {
            $lock->release();
        }
    }
}

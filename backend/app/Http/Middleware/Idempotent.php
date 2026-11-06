<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money is never taken twice on a bad network (api-outline.md): the app sends an
 * Idempotency-Key with each payment. If the same key arrives again (the internet dropped after
 * the server had already saved it, or a second tap), the first answer is sent back, nothing new is saved.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > 100 || ! $request->user()) {
            return $next($request);
        }
        $base = 'idem:'.$request->user()->id.':'.sha1($request->method().' '.$request->path().' '.$key);

        if ($saved = Cache::get($base)) {
            return response()->json($saved['body'], $saved['status'])->header('Idempotent-Replay', 'true');
        }
        if (! Cache::add($base.':busy', 1, 60)) {
            return response()->json(['message' => 'This payment is still being saved. Please wait a moment.', 'code' => 'busy'], 409);
        }
        try {
            $response = $next($request);
            if ($response instanceof JsonResponse && $response->getStatusCode() < 300) {
                Cache::put($base, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], now()->addDay());
            }

            return $response;
        } finally {
            Cache::forget($base.':busy');
        }
    }
}

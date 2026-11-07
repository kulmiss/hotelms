<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filters in the address (?q=…&status=…) are always single values in this app. A list there
 * (?q[]=x) is a broken or hand-made link: answer "not valid" instead of a server error.
 */
class ScalarQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($request->query() as $value) {
            if (is_array($value)) {
                return response()->json(['message' => 'One of the filters in the link is not valid.', 'code' => 'invalid_filter'], 422);
            }
        }

        return $next($request);
    }
}

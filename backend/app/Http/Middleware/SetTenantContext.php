<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scopes the request to the signed-in user's hotel.
 * The hotel always comes from the user, never from the client.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->set($request->user()?->hotel_id);

        return $next($request);
    }
}

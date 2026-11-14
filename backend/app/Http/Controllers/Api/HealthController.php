<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Simple status check used by the frontend, Docker and uptime monitors.
     */
    public function __invoke(): JsonResponse
    {
        $database = $this->check(fn () => DB::connection()->getPdo());

        return response()->json([
            'status' => $database ? 'ok' : 'degraded',
            'app' => config('app.name'),
            // The version only for the HotelMS team (SECURITY_REPORT L9); the public answer says ok / down.
            'version' => auth('web')->user()?->is_super_admin ? config('app.version') : null,
            'time' => now()->toIso8601String(),
            'checks' => [
                'database' => $database ? 'ok' : 'down',
            ],
        ], $database ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

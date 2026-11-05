<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationStopController extends Controller
{
    /** Leave the hotel and go back to the Super Admin account. */
    public function __invoke(Request $request, Impersonation $impersonation): JsonResponse
    {
        abort_unless($impersonation->stop($request), 404);

        return response()->json(['message' => 'Back to HotelMS admin.']);
    }
}

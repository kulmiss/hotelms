<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

class BrandingController extends Controller
{
    /** Public: name, colour, logo and sign-in message for every screen. */
    public function __invoke(): JsonResponse
    {
        $all = PlatformSetting::allValues();
        $public = array_intersect_key($all, array_flip(PlatformSetting::PUBLIC));
        $public['logo_url'] = PlatformSettingsController::logoUrl($all['brand_logo_path'] ?? null);

        return response()->json(['data' => $public])->header('Cache-Control', 'no-store');
    }
}

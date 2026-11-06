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

    /** Install the web app on a tablet or phone (name and colour from the branding). */
    public function manifest(): JsonResponse
    {
        $all = PlatformSetting::allValues();
        $name = (string) ($all['platform_name'] ?? 'Hotel');
        $color = (string) ($all['brand_color'] ?? '#0F766E');

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => $color,
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }
}

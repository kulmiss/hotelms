<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request, ActivityLogger $log): JsonResponse
    {
        $data = $request->validate([
            'platform_name' => ['sometimes', 'required', 'string', 'max:40'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'demo_public' => ['sometimes', 'boolean'],
            'default_trial_days' => ['sometimes', 'integer', 'between:0,365'],
            'default_trial_plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'grace_days' => ['sometimes', 'integer', 'between:0,30'],
            'brand_color' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'login_message' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email_from_name' => ['sometimes', 'required', 'string', 'max:60'],
            'email_footer' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $before = PlatformSetting::allValues();
        foreach ($data as $key => $value) {
            PlatformSetting::write($key, $value);
        }
        $changed = array_keys(array_diff_assoc(array_map('json_encode', $data), array_map('json_encode', array_intersect_key($before, $data))));
        if ($changed) {
            $log->log('platform.settings', 'Platform settings changed: '.implode(', ', $changed), null, [
                'old' => array_intersect_key($before, array_flip($changed)),
                'new' => array_intersect_key($data, array_flip($changed)),
            ]);
        }

        return response()->json(['data' => $this->present()]);
    }

    /** Upload or remove the logo shown in the sidebar, sign-in page and emails. */
    public function logo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024']]);

        $old = PlatformSetting::read('brand_logo_path');
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        $path = $request->file('logo')?->store('branding', 'public');
        PlatformSetting::write('brand_logo_path', $path);

        return response()->json(['data' => $this->present()]);
    }

    /** @return array<string, mixed> */
    private function present(): array
    {
        $values = PlatformSetting::allValues();
        $values['brand_logo_url'] = self::logoUrl($values['brand_logo_path'] ?? null);

        return $values;
    }

    public static function logoUrl(?string $path): ?string
    {
        return $path ? '/storage/'.ltrim($path, '/') : null;
    }
}

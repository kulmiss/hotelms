<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => PlatformSetting::allValues()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_trial_days' => ['sometimes', 'integer', 'between:0,365'],
            'default_trial_plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'grace_days' => ['sometimes', 'integer', 'between:0,30'],
        ]);

        foreach ($data as $key => $value) {
            PlatformSetting::write($key, $value);
        }

        return response()->json(['data' => PlatformSetting::allValues()]);
    }
}

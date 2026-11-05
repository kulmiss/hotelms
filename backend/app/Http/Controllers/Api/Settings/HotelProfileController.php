<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\HotelSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Hotel profile, logo and invoice settings (Hotel Admin). */
class HotelProfileController extends Controller
{
    public function __construct(private HotelSettings $settings) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tin' => ['nullable', 'string', 'max:30'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:200'],
            'invoice_prefix' => ['required', 'alpha_dash', 'max:10'],
            'invoice_footer' => ['nullable', 'string', 'max:300'],
        ]);

        $request->user()->hotel->update(collect($data)->except(['invoice_prefix', 'invoice_footer'])->all());
        $this->settings->put(['invoice_prefix' => strtoupper($data['invoice_prefix']), 'invoice_footer' => $data['invoice_footer'] ?? null]);

        return response()->json(['data' => $this->present($request)]);
    }

    public function logo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024']]);
        $hotel = $request->user()->hotel;

        if ($hotel->logo_path) {
            Storage::disk('public')->delete($hotel->logo_path);
        }
        $hotel->update(['logo_path' => $request->file('logo')?->store("hotels/{$hotel->id}", 'public')]);

        return response()->json(['data' => $this->present($request)]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request): array
    {
        $hotel = $request->user()->hotel->fresh();

        return $hotel->only(['name', 'legal_name', 'tin', 'vat_number', 'email', 'phone', 'city', 'address', 'default_currency'])
            + ['logo_url' => $hotel->logo_path ? '/storage/'.$hotel->logo_path : null]
            + $this->settings->group(HotelSettings::INVOICE_DEFAULTS);
    }
}

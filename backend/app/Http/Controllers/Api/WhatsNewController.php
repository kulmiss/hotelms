<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HotelInvoice;
use App\Models\PlatformSetting;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "What's new" for everyone, and a hotel's own subscription invoice to view and print. */
class WhatsNewController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Release::where('status', 'published')->orderByDesc('published_at')->limit(10)->get(['id', 'version', 'title', 'body', 'published_at']),
            'version' => config('app.version'),
        ]);
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        $hotel = $request->user()->hotel;
        abort_unless($hotel, 404);
        $invoice = HotelInvoice::where('hotel_id', $hotel->id)->with('plan:id,name')->findOrFail($id);

        return response()->json(['data' => $invoice->present() + [
            'hotel' => ['name' => $hotel->name, 'legal_name' => $hotel->legal_name, 'tin' => $hotel->tin, 'address' => $hotel->address, 'city' => $hotel->city],
            'from' => [
                'name' => PlatformSetting::read('billing_name') ?: PlatformSetting::read('platform_name'), 'tin' => PlatformSetting::read('billing_tin'),
                'address' => PlatformSetting::read('billing_address'), 'email' => PlatformSetting::read('support_email'), 'phone' => PlatformSetting::read('support_phone'),
                'payment_instructions' => PlatformSetting::read('payment_instructions'),
            ],
        ]]);
    }
}

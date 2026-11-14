<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Room;
use App\Services\Messages\GuestMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Printable room cards (owner, 14 Nov 2026): for each room, QR codes for the Wi-Fi, the restaurant menu and reception's
 * WhatsApp (Settings → Guest messages → Guest information). The QR codes are drawn in the browser.
 */
class RoomCardsController extends Controller
{
    public function __invoke(Request $request, GuestMessenger $messenger): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;
        $branches = $user->accessibleBranches();
        $branch = $branches->firstWhere('id', $request->integer('branch_id')) ?? $branches->first();
        abort_unless($branch instanceof Branch, 404);
        $info = $messenger->info();
        $wa = preg_replace('/\D+/', '', (string) $info['whatsapp']);

        return response()->json(['data' => [
            'hotel' => ['name' => $hotel->name, 'logo_url' => $hotel->logo_path ? '/storage/'.ltrim($hotel->logo_path, '/') : null, 'phone' => $branch->phone ?: $hotel->phone],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'wifi' => $info['wifi_name'] ? ['name' => $info['wifi_name'], 'password' => $info['wifi_password']] : null,
            'menu_url' => $info['menu_url'],
            'whatsapp' => $wa !== '' ? ['number' => $info['whatsapp'], 'url' => "https://wa.me/{$wa}"] : null,
            'breakfast' => $info['breakfast'],
            'rooms' => Room::with('roomType:id,name')->where('branch_id', $branch->id)->orderByRaw('LENGTH(number)')->orderBy('number')->get()
                ->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number, 'room_type' => $r->roomType?->name]),
        ]]);
    }
}

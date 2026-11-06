<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin → Activity log (area 11): every change made by the HotelMS team. */
class PlatformActivityController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'event' => ['nullable', 'string', 'max:40'], 'page' => ['nullable', 'integer', 'min:1']]);

        $page = $tenant->acrossAllHotels(fn () => ActivityLog::query()
            // Platform entries have no hotel; HotelMS team actions inside hotels are listed too.
            ->where(fn ($q) => $q->whereNull('hotel_id')->orWhereHas('user', fn ($u) => $u->where('is_super_admin', true)))
            // "Log in as hotel" is written twice (platform log and hotel log): show it once here.
            ->whereNot(fn ($q) => $q->whereNotNull('hotel_id')->where('event', 'support.login_as'))
            ->when($f['event'] ?? null, fn ($q, $e) => $q->where('event', 'like', addcslashes($e, '%_\\').'%'))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('description', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->with(['user:id,name', 'hotel:id,name'])->latest('id')->paginate(50));

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $a) => [
                'id' => $a->id, 'event' => $a->event, 'description' => $a->description, 'user' => $a->user?->name,
                'hotel' => $a->hotel ? ['id' => $a->hotel->id, 'name' => $a->hotel->name]
                    : (isset($a->properties['hotel_id'], $a->properties['hotel']) ? ['id' => (int) $a->properties['hotel_id'], 'name' => (string) $a->properties['hotel']] : null),
                'ip' => $a->ip_address, 'at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()],
        ]);
    }
}

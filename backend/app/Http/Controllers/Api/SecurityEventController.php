<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Security\SecurityLog;
use App\Support\HotelClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The security event log (SECURITY_REPORT M9): the hotel's own rows for its admins, everything for the HotelMS team. */
class SecurityEventController extends Controller
{
    /** Activity log → Security (activity_log.view): only this hotel's people. */
    public function hotel(Request $request): JsonResponse
    {
        $hotel = $request->user()->hotel;
        abort_unless($hotel !== null, 403);

        return $this->list($request, DB::table('security_events as e')->where('e.hotel_id', $hotel->id), fn ($t) => HotelClock::iso(Carbon::parse($t), $hotel), false);
    }

    /** Super Admin → Watchdog (watchdog.view): every hotel and the HotelMS team; tries with unknown emails too. */
    public function platform(Request $request): JsonResponse
    {
        $q = DB::table('security_events as e')->leftJoin('hotels as h', 'h.id', '=', 'e.hotel_id')
            ->when($request->integer('hotel_id'), fn ($w, $id) => $w->where('e.hotel_id', $id));

        return $this->list($request, $q, fn ($t) => Carbon::parse($t)->toIso8601String(), true);
    }

    private function list(Request $request, Builder $q, callable $time, bool $platform): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys(SecurityLog::KINDS))],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $page = $q->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->when($data['kind'] ?? null, fn ($w, $k) => $w->where('e.kind', $k))
            ->when(trim((string) ($data['search'] ?? '')), fn ($w, $s) => $w->where(fn ($x) => $x->where('u.name', 'like', "%{$s}%")->orWhere('e.email', 'like', "%{$s}%")->orWhere('u.email', 'like', "%{$s}%")->orWhere('e.ip', $s)))
            ->orderByDesc('e.id')
            ->select(array_merge(['e.id', 'e.kind', 'e.email', 'e.ip', 'e.device', 'e.detail', 'e.created_at', 'u.name as user_name', 'u.email as user_email'], $platform ? ['h.name as hotel_name'] : []))
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn ($e) => [
                'id' => $e->id, 'kind' => $e->kind, 'label' => SecurityLog::KINDS[$e->kind] ?? $e->kind,
                'who' => $e->user_name, 'email' => $e->user_email ?? $e->email, 'ip' => $e->ip, 'device' => $e->device, 'detail' => $e->detail,
                'hotel' => $platform ? $e->hotel_name : null, 'at' => $time($e->created_at),
            ])->values(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'kinds' => SecurityLog::KINDS,
        ]);
    }
}

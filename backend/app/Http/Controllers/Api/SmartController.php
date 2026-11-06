<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Booking\Availability;
use App\Services\Modules\Cashier;
use App\Services\Smart\SmartHints;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ✨ Smart hints for one place on screen (smart-features.md). Each place checks its own permission. */
class SmartController extends Controller
{
    public function __invoke(Request $request, string $place, SmartHints $smart): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;
        $deny = fn () => abort(403, 'You may not see this.');
        $branchIds = fn () => $user->accessibleBranches()->pluck('id')->map(fn ($id) => (int) $id)
            ->when($request->integer('branch_id'), fn ($c, $b) => $c->filter(fn ($id) => $id === $b))->values()->all();
        $branch = function () use ($request, $user): int {
            $id = $request->integer('branch_id');
            abort_unless($id && $user->accessibleBranches()->contains('id', $id), 404);

            return $id;
        };

        if ($place === 'admin') {
            abort_unless($user->is_super_admin && ! $hotel, 404);

            return response()->json(['data' => $smart->admin()]);
        }
        abort_unless($hotel, 404);

        $hints = match ($place) {
            'dashboard' => $user->hasPermission('dashboard.view') || $user->isHotelAdmin() ? $smart->dashboard($hotel, $user, $branchIds()) : $deny(),
            'reservation', 'check_in' => (function () use ($request, $user, $hotel, $smart, $place) {
                abort_unless($user->hasPermission('reservations.view'), 403);
                $r = Reservation::whereIn('branch_id', $user->accessibleBranches()->pluck('id'))->findOrFail($request->integer('id'));

                return $place === 'reservation' ? $smart->reservation($hotel, $r) : $smart->checkIn($hotel, $r, app(Availability::class));
            })(),
            'guest_check' => $user->hasPermission('guests.view') ? $smart->guestCheck($hotel, $request->validate([
                'phone' => ['nullable', 'string', 'max:30'], 'id_number' => ['nullable', 'string', 'max:50'], 'first_name' => ['nullable', 'string', 'max:60'],
                'last_name' => ['nullable', 'string', 'max:60'], 'exclude_id' => ['nullable', 'integer'],
            ])) : $deny(),
            'cashier' => $user->hasPermission('cashier.own_shift') ? $smart->cashier($hotel, $user, app(Cashier::class)) : $deny(),
            'housekeeping' => $user->hasPermission('housekeeping.view') ? $smart->housekeeping($hotel, $branch()) : $deny(),
            'shifts' => $user->hasPermission('shifts.manage') ? $smart->shifts($hotel, $branch()) : $deny(),
            'report' => collect(['reports.revenue', 'reports.occupancy'])->contains(fn ($p) => $user->hasPermission($p))
                ? $smart->report($hotel, $branchIds(), ...$this->period($request))
                : $deny(),
            'rooms_settings' => $user->hasPermission('settings.rooms') ? $smart->roomsSettings($hotel) : $deny(),
            default => abort(404),
        };

        return response()->json(['data' => $hints]);
    }

    /** @return array{0: string, 1: string} at most 400 days, like the reports */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        abort_if(Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 400, 422, 'Choose at most 400 days.');

        return [$data['from'], $data['to']];
    }
}

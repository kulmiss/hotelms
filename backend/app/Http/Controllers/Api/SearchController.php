<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The search box at the top of every screen: guests, bookings, rooms and bills of your hotel
 * (only what your role may see, only your branches). The HotelMS team searches hotels and people.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $user = $request->user();
        $like = '%'.addcslashes($q, '%_\\').'%';
        $results = [];

        if ($user->is_super_admin && ! $user->hotel_id) {
            Hotel::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('city', 'like', $like))
                ->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'city', 'status'])
                ->each(function (Hotel $h) use (&$results) {
                    $results[] = ['type' => 'hotel', 'title' => $h->name, 'subtitle' => trim(($h->city ?? '').' · '.$h->status, ' ·'), 'link' => "/admin/hotels/{$h->id}"];
                });
            User::whereNotNull('hotel_id')->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->with('hotel:id,name')->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'email', 'hotel_id'])
                ->each(function (User $u) use (&$results) {
                    $results[] = ['type' => 'person', 'title' => $u->name, 'subtitle' => $u->email.' · '.($u->hotel?->name ?? ''), 'link' => "/admin/hotels/{$u->hotel_id}"];
                });

            return response()->json(['data' => $results]);
        }

        $branches = $user->accessibleBranches()->pluck('id');

        if ($user->hasPermission('reservations.view')) {
            Reservation::with('guest:id,first_name,last_name')->whereIn('branch_id', $branches)
                ->where(fn ($w) => $w->where('code', 'like', $like)->orWhereHas('guest', fn ($g) => $g->search($q)))
                ->orderByDesc('check_in_at')->limit(self::LIMIT)->get()
                ->each(function (Reservation $r) use (&$results) {
                    $results[] = ['type' => 'booking', 'title' => $r->code.' · '.trim(($r->guest?->first_name ?? '').' '.($r->guest?->last_name ?? '')), 'subtitle' => $r->status.' · '.$r->check_in_at?->format('d M Y'), 'link' => "/reservations/{$r->id}"];
                });
        }
        if ($user->hasPermission('guests.view')) {
            Guest::search($q)->orderBy('first_name')->limit(self::LIMIT)->get()
                ->each(function (Guest $g) use (&$results) {
                    $results[] = ['type' => 'guest', 'title' => trim($g->first_name.' '.$g->last_name), 'subtitle' => $g->phone ?? $g->email ?? '', 'link' => "/guests/{$g->id}"];
                });
        }
        if ($user->hasPermission('frontdesk.view')) {
            Room::with(['roomType:id,name', 'branch:id,name'])->whereIn('branch_id', $branches)->where('number', 'like', addcslashes($q, '%_\\').'%')
                ->orderByRaw('LENGTH(number)')->orderBy('number')->limit(self::LIMIT)->get()
                ->each(function (Room $r) use (&$results) {
                    $results[] = ['type' => 'room', 'title' => 'Room '.$r->number, 'subtitle' => ($r->roomType?->name ?? '').' · '.($r->branch?->name ?? '').' · '.$r->status, 'link' => '/rooms?branch='.$r->branch_id.'&room='.$r->id];
                });
        }
        if ($user->hasPermission('invoices.view')) {
            Invoice::whereIn('branch_id', $branches)->where('number', 'like', $like)->latest('id')->limit(self::LIMIT)->get(['id', 'number', 'status', 'total', 'currency'])
                ->each(function (Invoice $i) use (&$results) {
                    $results[] = ['type' => 'bill', 'title' => $i->number, 'subtitle' => $i->status.' · '.$i->currency.' '.$i->total, 'link' => "/billing/{$i->id}"];
                });
        }

        return response()->json(['data' => $results]);
    }
}

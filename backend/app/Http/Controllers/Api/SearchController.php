<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Booking\Availability;
use App\Services\Smart\SmartHints;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

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
        // ✨ Questions in plain words ("free rooms this weekend", "unpaid over 1000", "who is in room 305", "Meron's last stay").
        if (app(SmartHints::class)->on($user->hotel, 'smart_search')) {
            try {
                $results = array_merge($results, $this->answers($q, $user, $branches));
            } catch (Throwable $e) {
                report($e);
            }
        }

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

    /**
     * @param  Collection<int, int>  $branches
     * @return list<array<string, string>>
     */
    private function answers(string $q, User $user, Collection $branches): array
    {
        $hotel = $user->hotel;
        $text = mb_strtolower(trim($q));
        $out = [];

        // Free rooms today / tonight / tomorrow / this weekend.
        if ($user->hasPermission('reservations.view') && preg_match('/\b(free|available|empty)\b.*\brooms?\b|\brooms?\b.*\b(free|available)\b/', $text)) {
            $now = HotelClock::now($hotel)->startOfDay();
            [$from, $to, $label] = match (true) {
                str_contains($text, 'weekend') => [$now->isFriday() || $now->isSaturday() ? $now->copy() : $now->copy()->next(Carbon::FRIDAY), null, 'this weekend'],
                str_contains($text, 'tomorrow') => [$now->copy()->addDay(), null, 'tomorrow'],
                default => [$now->copy(), null, 'tonight'],
            };
            $from = $from->copy()->setTimeFromTimeString('14:00');
            $to = $label === 'this weekend' ? $from->copy()->addDays(2)->setTimeFromTimeString('12:00') : $from->copy()->addDay()->setTimeFromTimeString('12:00');
            $free = 0;
            $parts = [];
            foreach ($branches as $branchId) {
                foreach (app(Availability::class)->forBranch((int) $branchId, $from->copy()->utc(), $to->copy()->utc()) as $row) {
                    $free += $row['available'];
                    if ($row['available'] > 0) {
                        $parts[] = "{$row['available']} {$row['room_type']->name}";
                    }
                }
            }
            $out[] = ['type' => 'answer', 'title' => "{$free} free ".($free === 1 ? 'room' : 'rooms')." {$label}", 'subtitle' => implode(', ', array_slice($parts, 0, 4)) ?: 'Fully booked', 'link' => '/reservations/new'];
        }

        // Unpaid balances over an amount.
        if ($user->hasPermission('invoices.view') && preg_match('/\b(unpaid|owe|owing|balances?|debts?)\b/', $text)) {
            $min = preg_match('/(\d[\d,\.]*)/', $text, $m) ? str_replace(',', '', $m[1]) : '0';
            $rows = Invoice::whereIn('branch_id', $branches)->whereIn('status', ['open', 'partially_paid', 'outstanding'])->where('balance', '>', is_numeric($min) ? $min : 0);
            $count = (clone $rows)->count();
            $sum = Money::round((string) ((clone $rows)->sum('balance') ?: '0'));
            $out[] = ['type' => 'answer', 'title' => "{$count} unpaid ".($count === 1 ? 'bill' : 'bills').(Money::isPositive($min) ? " over {$min}" : ''), 'subtitle' => "{$hotel->default_currency} {$sum} owed in total", 'link' => '/billing'];
        }

        // Who is in room 305?
        if ($user->hasPermission('frontdesk.view') && preg_match('/\b(who|guest)\b.*\broom\s*([a-z0-9-]+)/', $text, $m)) {
            $stay = Reservation::whereIn('branch_id', $branches)->where('status', 'checked_in')
                ->whereHas('rooms', fn ($r) => $r->where('status', 'checked_in')->whereHas('room', fn ($x) => $x->where('number', $m[2])))->with('guest')->first();
            $out[] = $stay
                ? ['type' => 'answer', 'title' => "Room {$m[2]}: ".($stay->guest?->full_name ?? $stay->code), 'subtitle' => 'Leaves '.HotelClock::local($stay->check_out_at, $hotel)->format('D d M, H:i'), 'link' => "/reservations/{$stay->id}"]
                : ['type' => 'answer', 'title' => "Nobody is in room {$m[2]} now", 'subtitle' => 'No checked-in guest', 'link' => '/rooms'];
        }

        // Meron's last stay / last stay of Meron.
        if ($user->hasPermission('reservations.view') && preg_match("/^(.+?)(?:'s|’s)?\s+last\s+stay$|^last\s+stay\s+(?:of\s+)?(.+)$/", $text, $m)) {
            $name = trim($m[1] ?: ($m[2] ?? ''));
            $stay = mb_strlen($name) >= 2 ? Reservation::whereIn('branch_id', $branches)->where('status', 'checked_out')
                ->whereHas('guest', fn ($g) => $g->search($name))->with('guest', 'rooms.room')->orderByDesc('check_out_at')->first() : null;
            if ($stay) {
                $out[] = ['type' => 'answer', 'title' => ($stay->guest?->full_name ?? '')."'s last stay", 'subtitle' => HotelClock::local($stay->check_in_at, $hotel)->format('d M').' – '.HotelClock::local($stay->check_out_at, $hotel)->format('d M Y').' · room '.$stay->rooms->map(fn ($x) => $x->room?->number)->filter()->implode(', '), 'link' => "/reservations/{$stay->id}"];
            }
        }

        return $out;
    }
}

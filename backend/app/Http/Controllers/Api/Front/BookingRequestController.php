<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\Reservation;
use App\Services\ActivityLogger;
use App\Services\BookingPage;
use App\Services\Messages\GuestMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Requests from the public booking page, and its settings (Settings → Booking page). */
class BookingRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:new,accepted,declined']]);
        $branches = $request->user()->accessibleBranches()->pluck('id');
        $rows = BookingRequest::with(['branch:id,name', 'roomType:id,name'])->whereIn('branch_id', $branches)
            ->where('status', $data['status'] ?? 'new')->latest('id')->limit(100)->get();

        return response()->json([
            'data' => $rows->map->present()->values(),
            'new' => BookingRequest::whereIn('branch_id', $branches)->where('status', 'new')->count(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->find($request, $id)->present()]);
    }

    /** Booked with the normal form: link the booking (it must belong to this hotel). */
    public function accept(Request $request, int $id, ActivityLogger $log): JsonResponse
    {
        $data = $request->validate(['reservation_id' => ['required', 'integer']]);
        $r = $this->find($request, $id);
        $reservation = Reservation::find($data['reservation_id']) ?? throw ValidationException::withMessages(['reservation_id' => 'Booking not found.']);
        $r->update(['status' => 'accepted', 'reservation_id' => $reservation->id, 'handled_by' => $request->user()->id, 'handled_at' => now()]);
        // What the guest chose on the booking page carries over: the thank-you message and the language.
        if ($r->feedback_consent && ! $reservation->feedback_consent) {
            $reservation->update(['feedback_consent' => true]);
        }
        if ($r->language && $reservation->guest && ! $reservation->guest->language) {
            $reservation->guest->update(['language' => $r->language]);
        }
        $log->log('booking_request.accepted', "Booked the online request of {$r->name} ({$reservation->code})", $reservation);

        return response()->json(['data' => $r->fresh(['branch', 'roomType'])->present()]);
    }

    public function decline(Request $request, int $id, ActivityLogger $log): JsonResponse
    {
        $r = $this->find($request, $id);
        $r->update(['status' => 'declined', 'handled_by' => $request->user()->id, 'handled_at' => now()]);
        $log->log('booking_request.declined', "Declined the online request of {$r->name}", $r);
        if ($request->boolean('tell_guest', true)) {
            app(GuestMessenger::class)->sendForRequest($request->user()->hotel, $r, 'request_declined');
        }

        return response()->json(['data' => $r->fresh(['branch', 'roomType'])->present()]);
    }

    public function settings(Request $request, BookingPage $pages): JsonResponse
    {
        return response()->json(['data' => $pages->settingsFor($request->user()->hotel)]);
    }

    public function saveSettings(Request $request, BookingPage $pages, ActivityLogger $log): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'slug' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9 -]+$/'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $hotel = $request->user()->hotel;
        $pages->save($hotel, $data);
        $log->log('settings.booking_page', ($data['enabled'] ? 'Turned on' : 'Turned off').' the public booking page', $hotel);

        return response()->json(['data' => $pages->settingsFor($hotel->fresh())]);
    }

    private function find(Request $request, int $id): BookingRequest
    {
        $r = BookingRequest::with(['branch:id,name', 'roomType:id,name'])->whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
        if ($request->isMethod('post') && $r->status !== 'new') {
            throw ValidationException::withMessages(['status' => 'This request was already handled.']);
        }

        return $r;
    }
}

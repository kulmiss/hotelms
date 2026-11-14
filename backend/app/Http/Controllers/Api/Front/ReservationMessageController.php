<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Models\GuestMessage;
use App\Models\Reservation;
use App\Services\Messages\GuestMessenger;
use App\Services\Messages\MessageTemplates;
use App\Services\Messages\SmsGateway;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** A booking's messages: the log, a preview, and "send now" (email, SMS, or WhatsApp / SMS app by a tap). */
class ReservationMessageController extends Controller
{
    public function __construct(private GuestMessenger $messenger, private SmsGateway $sms) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $r = $this->find($request, $id);
        $hotel = $request->user()->hotel;
        $guest = $r->guest;

        return response()->json([
            'data' => GuestMessage::with('sender:id,name')->where('reservation_id', $r->id)->orderByDesc('id')->limit(100)->get()->map(fn (GuestMessage $m) => [
                'id' => $m->id, 'key' => $m->key, 'channel' => $m->channel, 'language' => $m->language, 'to' => $m->to, 'status' => $m->status, 'error' => $m->error,
                'by' => $m->sender?->name, 'at' => HotelClock::iso($m->created_at, $hotel),
            ]),
            'messages' => collect(MessageTemplates::keys())->reject(fn ($k) => str_starts_with($k, 'request_'))->map(fn ($k) => [
                'key' => $k, 'allowed' => $this->messenger->allowed($k, $guest, (bool) $r->feedback_consent),
            ])->values(),
            'channels' => ['email' => (bool) $guest?->email, 'phone' => (bool) $guest?->phone, 'sms' => $this->sms->ready() && (bool) $guest?->phone],
            'opt_out' => (bool) $guest?->messages_opt_out,
            'feedback_consent' => (bool) $r->feedback_consent,
            'language' => $guest?->language,
        ]);
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['key' => ['required', Rule::in(MessageTemplates::keys())], 'language' => ['nullable', Rule::in(MessageTemplates::LANGUAGES)]]);

        return response()->json(['data' => $this->messenger->preview($request->user()->hotel, $this->find($request, $id), $data['key'], $data['language'] ?? null)]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in(MessageTemplates::keys())],
            'channel' => ['required', Rule::in(GuestMessage::CHANNELS)],
            'language' => ['nullable', Rule::in(MessageTemplates::LANGUAGES)],
        ]);
        $reservation = $this->find($request, $id);
        try {
            $result = $this->messenger->sendNow($request->user()->hotel, $reservation, $data['key'], $data['channel'], $request->user(), $data['language'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['channel' => $e->getMessage()]);
        }

        return response()->json(['data' => $result]);
    }

    /** Yes / no to the thank-you message (the guest's answer, asked at the desk). */
    public function consent(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['feedback_consent' => ['required', 'boolean']]);
        $this->find($request, $id)->update($data);

        return response()->json(['data' => $data]);
    }

    private function find(Request $request, string $id): Reservation
    {
        return Reservation::with(['guest', 'branch'])->whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }
}

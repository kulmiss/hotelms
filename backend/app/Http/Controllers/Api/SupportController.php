<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Notify\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hotel side of the support inbox: "Help" in the user menu. Staff see their own requests;
 * the Hotel Admin sees all of the hotel's. HotelMS internal notes are never sent here.
 */
class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->hotelUser($request);
        $rows = SupportRequest::query()
            ->when(! $user->isHotelAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->with('user:id,name')->orderByRaw("status = 'solved'")->orderByDesc('last_message_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (SupportRequest $r) => $this->summary($r))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->hotelUser($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'priority' => ['sometimes', 'in:normal,urgent'],
        ]);
        $req = DB::transaction(function () use ($data, $request) {
            $req = SupportRequest::create([
                'user_id' => $request->user()->id, 'channel' => 'app', 'subject' => $data['subject'],
                'priority' => $data['priority'] ?? 'normal', 'status' => 'open', 'last_message_at' => now(),
            ]);
            $req->messages()->create(['user_id' => $request->user()->id, 'kind' => 'message', 'body' => $data['body']]);

            return $req;
        });
        if ($req->priority === 'urgent') {
            app(Notifier::class)->platform("Urgent support request from {$request->user()->hotel?->name}: {$req->subject}");
        }

        return response()->json(['data' => $this->detail($req->fresh())], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->find($request, $id))]);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $req = $this->find($request, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($req, $data, $request) {
            $req->messages()->create(['user_id' => $request->user()->id, 'kind' => 'message', 'body' => $data['body']]);
            // An answer from the hotel puts it back in HotelMS's open list.
            $req->update(['status' => 'open', 'last_message_at' => now()]);
        });

        return response()->json(['data' => $this->detail($req->fresh())]);
    }

    /** Only hotel staff use this side (the HotelMS team answers in Super Admin → Support). */
    private function hotelUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hotel_id !== null, 404);

        return $user;
    }

    private function find(Request $request, string $id): SupportRequest
    {
        $user = $this->hotelUser($request);

        return SupportRequest::query()->when(! $user->isHotelAdmin(), fn ($q) => $q->where('user_id', $user->id))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function summary(SupportRequest $r): array
    {
        return [
            'id' => $r->id, 'subject' => $r->subject, 'status' => $r->status, 'priority' => $r->priority, 'channel' => $r->channel,
            'from' => $r->user?->name, 'last_message_at' => $r->last_message_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(SupportRequest $r): array
    {
        $r->load(['user:id,name', 'messages' => fn ($q) => $q->where('kind', '!=', 'note'), 'messages.user:id,name,is_super_admin']);

        return $this->summary($r) + [
            'messages' => $r->messages->map(fn (SupportMessage $m) => [
                'id' => $m->id, 'kind' => $m->kind, 'body' => $m->body,
                // HotelMS staff are shown as "HotelMS support", not by name.
                'author' => $m->kind === 'reply' ? null : $m->user?->name,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ];
    }
}

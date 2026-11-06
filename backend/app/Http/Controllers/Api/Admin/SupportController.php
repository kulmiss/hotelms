<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Super Admin → Support inbox (super-admin.md area 7): requests from the app, Telegram and phone calls. */
class SupportController extends Controller
{
    /** @return Builder<SupportRequest> */
    private function query(): Builder
    {
        return SupportRequest::withoutGlobalScope('hotel');
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(SupportRequest::STATUSES)],
            'hotel_id' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $rows = $this->query()->with(['hotel:id,name', 'user:id,name', 'assignee:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['hotel_id'] ?? null, fn ($q, $h) => $q->where('hotel_id', $h))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('subject', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->orderByRaw("priority = 'urgent' desc")->orderByDesc('last_message_at')->paginate(30);
        $counts = $this->query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'data' => collect($rows->items())->map(fn (SupportRequest $r) => $this->summary($r))->values(),
            'counts' => collect(SupportRequest::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'meta' => ['total' => $rows->total(), 'last_page' => $rows->lastPage(), 'current_page' => $rows->currentPage()],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->query()->findOrFail($id))]);
    }

    /** Log a phone call or a Telegram message as a request (or a note about a call). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hotel_id' => ['required', 'integer', Rule::exists('hotels', 'id')],
            'channel' => ['required', Rule::in(['phone', 'telegram'])],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'priority' => ['sometimes', 'in:normal,urgent'],
            'status' => ['sometimes', Rule::in(SupportRequest::STATUSES)],
        ]);
        $req = DB::transaction(function () use ($data, $request) {
            $req = SupportRequest::create([
                'hotel_id' => $data['hotel_id'], 'user_id' => null, 'channel' => $data['channel'], 'subject' => $data['subject'],
                'priority' => $data['priority'] ?? 'normal', 'status' => $data['status'] ?? 'open', 'assigned_to' => $request->user()->id, 'last_message_at' => now(),
            ]);
            $req->messages()->create(['user_id' => $request->user()->id, 'kind' => 'message', 'body' => $data['body']]);

            return $req;
        });

        return response()->json(['data' => $this->detail($req)], 201);
    }

    /** A reply the hotel sees, or an internal note only HotelMS sees. */
    public function message(Request $request, string $id): JsonResponse
    {
        $req = $this->query()->findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'kind' => ['required', Rule::in(['reply', 'note'])], 'solve' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($req, $data, $request) {
            $req->messages()->create(['user_id' => $request->user()->id, 'kind' => $data['kind'], 'body' => $data['body']]);
            if ($data['kind'] === 'reply') {
                // After our answer we wait for the hotel (or it is solved).
                $req->update(['status' => ($data['solve'] ?? false) ? 'solved' : 'waiting', 'last_message_at' => now(), 'assigned_to' => $req->assigned_to ?? $request->user()->id]);
            }
        });

        return response()->json(['data' => $this->detail($req->fresh())]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $req = $this->query()->findOrFail($id);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportRequest::STATUSES)],
            'priority' => ['sometimes', 'in:normal,urgent'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
        ]);
        if (! empty($data['assigned_to']) && ! User::whereKey($data['assigned_to'])->where('is_super_admin', true)->where('status', 'active')->exists()) {
            abort(422, 'Choose a HotelMS team member.');
        }
        $old = $req->only(array_keys($data));
        $req->update($data);
        $changes = collect($data)->filter(fn ($v, $k) => ($old[$k] ?? null) != $v);
        if ($changes->isNotEmpty()) {
            $text = $changes->map(fn ($v, $k) => match ($k) {
                'status' => "Status: {$v}",
                'priority' => "Priority: {$v}",
                default => 'Assigned to: '.($v ? User::whereKey($v)->value('name') : 'nobody'),
            })->implode(' · ');
            $req->messages()->create(['user_id' => $request->user()->id, 'kind' => 'system', 'body' => $text]);
        }

        return response()->json(['data' => $this->detail($req->fresh())]);
    }

    /** @return array<string, mixed> */
    private function summary(SupportRequest $r): array
    {
        return [
            'id' => $r->id, 'subject' => $r->subject, 'status' => $r->status, 'priority' => $r->priority, 'channel' => $r->channel,
            'hotel' => $r->hotel ? ['id' => $r->hotel->id, 'name' => $r->hotel->name] : null,
            'from' => $r->user?->name, 'assigned_to' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
            'last_message_at' => $r->last_message_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(SupportRequest $r): array
    {
        $r->load(['hotel' => fn ($q) => $q->withTrashed()->with('plan:id,name'), 'user:id,name,email,phone', 'assignee:id,name', 'messages.user:id,name,is_super_admin']);
        $hotel = $r->hotel;

        return $this->summary($r) + [
            'contact' => $r->user ? ['name' => $r->user->name, 'email' => $r->user->email, 'phone' => $r->user->phone] : null,
            'hotel_context' => $hotel ? ['status' => $hotel->status, 'plan' => $hotel->plan?->name, 'city' => $hotel->city, 'phone' => $hotel->phone, 'email' => $hotel->email] : null,
            'messages' => $r->messages->map(fn (SupportMessage $m) => [
                'id' => $m->id, 'kind' => $m->kind, 'body' => $m->body, 'author' => $m->user?->name,
                'from_hotelms' => (bool) $m->user?->is_super_admin, 'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'team' => User::where('is_super_admin', true)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ];
    }
}

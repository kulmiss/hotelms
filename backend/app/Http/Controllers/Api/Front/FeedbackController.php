<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Models\GuestFeedback;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Switches;
use App\Support\HotelClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Guest feedback for the hotel (permission feedback.view): the answers with a reply note, and on Starter and up the
 * dashboard: averages, the trend by month, per room and per staff (who checked the guest in and out).
 * The guest's stars and words can never be changed here.
 */
class FeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['low' => ['nullable', 'boolean'], 'branch_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'], 'id' => ['nullable', 'integer']]);
        $hotel = $request->user()->hotel;
        $page = $this->scope($request)->with(['guest:id,first_name,last_name', 'room:id,number', 'reservation:id,code'])
            ->when($data['low'] ?? false, fn ($q) => $q->where('overall', '<=', 3))
            ->when($data['id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderByDesc('submitted_at')->paginate(25);
        $names = User::whereIn('id', $page->getCollection()->flatMap(fn ($f) => [$f->checked_in_by, $f->checked_out_by, $f->replied_by])->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (GuestFeedback $f) => [
                'id' => $f->id, 'reservation_id' => $f->reservation_id, 'code' => $f->reservation?->code, 'guest' => $f->guest?->full_name, 'room' => $f->room?->number,
                ...$f->only(GuestFeedback::RATINGS), 'comment' => $f->comment, 'public_ok' => $f->public_ok, 'language' => $f->language,
                'submitted_at' => HotelClock::iso($f->submitted_at, $hotel), 'checked_in_by' => $names[$f->checked_in_by] ?? null, 'checked_out_by' => $names[$f->checked_out_by] ?? null,
                'reply' => $f->reply, 'replied_by' => $names[$f->replied_by] ?? null, 'replied_at' => HotelClock::iso($f->replied_at, $hotel),
            ]),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $hotel = $request->user()->hotel;
        $avg = fn (Builder $q) => collect(GuestFeedback::RATINGS)->mapWithKeys(fn ($k) => [$k => $this->round((clone $q)->avg($k))])->all();
        $base = $this->scope($request);
        $out = ['count' => (clone $base)->count(), 'averages' => $avg(clone $base), 'low' => (clone $base)->where('overall', '<=', 3)->count(), 'dashboard' => false];
        if (! app(Switches::class)->on($hotel, 'feature.feedback_dashboard')) {
            return response()->json(['data' => $out]); // Free plan: the list, the count and the averages
        }
        $month = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', submitted_at)" : "DATE_FORMAT(submitted_at, '%Y-%m')";
        $out['dashboard'] = true;
        $out['trend'] = (clone $base)->where('submitted_at', '>=', now()->subMonths(11)->startOfMonth())
            ->selectRaw("{$month} as month, count(*) as n, avg(overall) as overall, avg(cleanliness) as cleanliness, avg(staff) as staff")
            ->groupBy('month')->orderBy('month')->get()->map(fn ($r) => ['month' => $r->month, 'count' => (int) $r->n, 'overall' => $this->round($r->overall), 'cleanliness' => $this->round($r->cleanliness), 'staff' => $this->round($r->staff)]);
        $out['rooms'] = (clone $base)->whereNotNull('guest_feedback.room_id')->join('rooms', 'rooms.id', '=', 'guest_feedback.room_id')
            ->selectRaw('rooms.number as room, count(*) as n, avg(overall) as overall, avg(cleanliness) as cleanliness')->groupBy('rooms.number')->orderBy('overall')->limit(50)->get()
            ->map(fn ($r) => ['room' => $r->room, 'count' => (int) $r->n, 'overall' => $this->round($r->overall), 'cleanliness' => $this->round($r->cleanliness)]);
        $people = [];
        foreach (['checked_in_by' => 'check_in', 'checked_out_by' => 'check_out'] as $col => $role) {
            foreach ((clone $base)->whereNotNull($col)->selectRaw("{$col} as uid, count(*) as n, avg(overall) as overall, avg(staff) as staff")->groupBy($col)->get() as $r) {
                $people[] = ['user_id' => (int) $r->uid, 'role' => $role, 'count' => (int) $r->n, 'overall' => $this->round($r->overall), 'staff' => $this->round($r->staff)];
            }
        }
        $names = User::whereIn('id', array_column($people, 'user_id'))->pluck('name', 'id');
        $out['staff'] = array_map(fn ($p) => $p + ['name' => $names[$p['user_id']] ?? '—'], $people);

        return response()->json(['data' => $out]);
    }

    /** The hotel's reply note (the guest's words stay as they were). */
    public function reply(Request $request, string $id, ActivityLogger $log): JsonResponse
    {
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:2000']]);
        $f = $this->scope($request)->findOrFail($id);
        $f->update(['reply' => $data['reply'] ?: null, 'replied_by' => $data['reply'] ? $request->user()->id : null, 'replied_at' => $data['reply'] ? now() : null]);
        $log->log('feedback.reply', 'Reply note on guest feedback #'.$f->id, $f);

        return response()->json(['data' => ['reply' => $f->reply]]);
    }

    /** Sent answers of the branches this person works in. */
    private function scope(Request $request): Builder
    {
        $branches = $request->user()->accessibleBranches()->pluck('id');

        return GuestFeedback::query()->whereNotNull('submitted_at')->whereIn('guest_feedback.branch_id', $branches)
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('guest_feedback.branch_id', $b));
    }

    private function round(mixed $v): ?float
    {
        return $v === null ? null : round((float) $v, 1);
    }
}

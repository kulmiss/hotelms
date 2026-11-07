<?php

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\Booking\ReservationService;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Approvals inbox (automation.md): price suggestions and refunds that automations prepared.
 * Nothing changes prices or moves money until a manager approves it here.
 */
class ApprovalController extends Controller
{
    public function __construct(private ActivityLogger $log) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'expired'])]])['status'] ?? 'pending';
        $branches = $request->user()->accessibleBranches()->pluck('id');
        $this->expireOld($request);
        $rows = Approval::where('status', $status)->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branches))
            ->with(['branch:id,name', 'decider:id,name'])->latest('id')->limit(100)->get();

        return response()->json([
            'data' => $rows->map(fn (Approval $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'title' => $a->title, 'payload' => $a->payload, 'status' => $a->status, 'branch' => $a->branch?->name,
                'decided_by' => $a->decider?->name, 'decided_at' => $a->decided_at?->toIso8601String(), 'note' => $a->note, 'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'pending' => Approval::where('status', 'pending')->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branches))->count(),
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $approval = $this->find($request, $id);
        $user = $request->user();

        DB::transaction(function () use ($approval, $request, $user) {
            // Two managers tapping Approve at the same moment: only the first one acts (no double refund).
            if (Approval::whereKey($approval->id)->lockForUpdate()->value('status') !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'Someone already decided this.']);
            }
            if ($approval->kind === 'refund') {
                abort_unless($user->hasPermission('payments.refund'), 403, 'You may not give refunds.');
                $data = $request->validate(['payment_method_id' => ['required', 'integer'], 'reference' => ['nullable', 'string', 'max:100']]);
                $reservation = Reservation::findOrFail($approval->payload['reservation_id']);
                app(ReservationService::class)->recordPayment($reservation, [
                    'type' => 'refund', 'payment_method_id' => $data['payment_method_id'], 'amount' => $approval->payload['amount'],
                    'currency' => $approval->payload['currency'], 'reference' => $data['reference'] ?? null, 'refund_reason' => 'Cancelled booking (approved refund)',
                ], $user);
            } else {
                abort_unless($user->hasPermission('settings.pricing'), 403, 'You may not change prices.');
                // One-day price rule for every room type of the branch.
                $date = $approval->payload['date'];
                $percent = (int) $approval->payload['percent'];
                RoomType::where('branch_id', $approval->branch_id)->get()->each(fn (RoomType $type) => RateRule::create([
                    'room_type_id' => $type->id, 'name' => ($percent > 0 ? '+' : '').$percent.'% on '.Carbon::parse($date)->format('d M').' (approved suggestion)',
                    'rule_type' => 'season', 'start_date' => $date, 'end_date' => $date, 'adjustment' => 'percent', 'value' => $percent, 'priority' => 50, 'is_active' => true,
                ]));
            }
            $approval->update(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => now()]);
        });
        $this->log->log('approval.approved', "Approved: {$approval->title}", $approval);

        return response()->json(['message' => 'Approved.']);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $approval = $this->find($request, $id);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:200']])['note'] ?? null;
        $done = Approval::whereKey($approval->id)->where('status', 'pending')
            ->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'note' => $note]);
        if ($done !== 1) {
            throw ValidationException::withMessages(['approval' => 'Someone already decided this.']);
        }
        $this->log->log('approval.rejected', "Rejected: {$approval->title}", $approval);

        return response()->json(['message' => 'Rejected.']);
    }

    private function find(Request $request, string $id): Approval
    {
        $approval = Approval::where('status', 'pending')->findOrFail($id);
        abort_if($approval->branch_id && ! $request->user()->accessibleBranches()->contains('id', $approval->branch_id), 404);
        if (str_starts_with($approval->kind, 'price_') && $approval->payload['date'] < HotelClock::now($request->user()->hotel)->toDateString()) {
            $approval->update(['status' => 'expired']);
            throw ValidationException::withMessages(['approval' => 'This date has passed.']);
        }

        return $approval;
    }

    /** Price suggestions for dates that have passed can no longer be approved. */
    private function expireOld(Request $request): void
    {
        $today = HotelClock::now($request->user()->hotel)->toDateString(); // the hotel's day, not the server's
        Approval::where('status', 'pending')->where('kind', 'like', 'price_%')->get()
            ->filter(fn (Approval $a) => ($a->payload['date'] ?? '9999') < $today)
            ->each(fn (Approval $a) => $a->update(['status' => 'expired']));
    }
}

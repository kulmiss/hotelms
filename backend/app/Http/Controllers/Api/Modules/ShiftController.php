<?php

namespace App\Http\Controllers\Api\Modules;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Shift templates and the weekly roster (screen-list.md 9.4, 9.5). */
class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Shift::with('branch:id,name')->whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->orderBy('branch_id')->orderBy('start_time')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Shift::create($this->validated($request))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $shift = $this->find($request, $id);
        $shift->update($this->validated($request));

        return response()->json(['data' => $shift->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** Roster for a week (or any range up to 31 days). */
    public function roster(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'days' => ['nullable', 'integer', 'between:1,31'], 'branch_id' => ['nullable', 'integer']]);
        $from = Carbon::parse($data['from'])->toDateString();
        $to = Carbon::parse($data['from'])->addDays(($data['days'] ?? 7) - 1)->toDateString();
        $branchIds = $this->branchIds($request);

        $assignments = ShiftAssignment::with(['shift:id,name,start_time,end_time', 'user:id,name'])->whereIn('branch_id', $branchIds)
            ->whereBetween('date', [$from, $to])->where('status', '!=', 'cancelled')->get();
        $staff = User::where('hotel_id', $request->user()->hotel_id)->where('status', 'active')->whereNull('deleted_at')
            ->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds))->orderBy('name')->get(['id', 'name']);
        // Hotel Admins see all branches without being attached; include the people already on the roster.
        $staff = $staff->concat($assignments->pluck('user')->filter())->unique('id')->values();

        return response()->json([
            'from' => $from, 'to' => $to,
            'shifts' => Shift::whereIn('branch_id', $branchIds)->orderBy('start_time')->get(),
            'staff' => $staff->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'assignments' => $assignments->map(fn (ShiftAssignment $a) => ['id' => $a->id, 'user_id' => $a->user_id, 'shift_id' => $a->shift_id, 'date' => $a->date->toDateString(), 'shift' => $a->shift?->only(['name', 'start_time', 'end_time'])]),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['user_id' => ['required', 'integer'], 'shift_id' => ['required', 'integer'], 'date' => ['required', 'date', 'after:-1 year', 'before:+1 year']],
            ['date.after' => 'Choose a date in the last year.', 'date.before' => 'Choose a date in the next year.'],
        );
        $shift = $this->find($request, (string) $data['shift_id']);
        if (! User::where('hotel_id', $request->user()->hotel_id)->where('status', 'active')->whereKey($data['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Choose a staff member.']);
        }
        $a = ShiftAssignment::updateOrCreate(
            ['user_id' => $data['user_id'], 'shift_id' => $shift->id, 'date' => Carbon::parse($data['date'])->toDateString()],
            ['branch_id' => $shift->branch_id, 'status' => 'scheduled'],
        );

        return response()->json(['data' => $a], 201);
    }

    public function unassign(Request $request, string $id): JsonResponse
    {
        ShiftAssignment::whereIn('branch_id', $this->branchIds($request))->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** My shifts for the next two weeks. */
    public function mine(Request $request): JsonResponse
    {
        $rows = ShiftAssignment::with(['shift:id,name,start_time,end_time', 'shift.branch:id,name'])->where('user_id', $request->user()->id)
            ->where('date', '>=', now()->subDay()->toDateString())->where('date', '<=', now()->addDays(14)->toDateString())
            ->where('status', '!=', 'cancelled')->orderBy('date')->get();

        return response()->json(['data' => $rows->map(fn ($a) => ['id' => $a->id, 'date' => $a->date->toDateString(), 'shift' => $a->shift?->only(['name', 'start_time', 'end_time']), 'branch' => $a->shift?->branch?->name])]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:40'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
        ]);
        if (! in_array((int) $data['branch_id'], $request->user()->accessibleBranches()->pluck('id')->all(), true)) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }

        return $data;
    }

    private function find(Request $request, string $id): Shift
    {
        return Shift::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }

    /** @return list<int> */
    private function branchIds(Request $request): array
    {
        $ids = $request->user()->accessibleBranches()->pluck('id')->all();
        $b = $request->integer('branch_id');

        return $b && in_array($b, $ids, true) ? [$b] : $ids;
    }
}

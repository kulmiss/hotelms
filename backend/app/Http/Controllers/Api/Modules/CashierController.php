<?php

namespace App\Http\Controllers\Api\Modules;

use App\Http\Controllers\Controller;
use App\Models\CashierSession;
use App\Models\CashierSessionBalance;
use App\Models\Payment;
use App\Services\Modules\Cashier;
use App\Support\HotelClock;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Cashier shift close (screen-list.md section 7). */
class CashierController extends Controller
{
    public function __construct(private Cashier $cashier) {}

    /** My open shift with cash taken so far. */
    public function current(Request $request): JsonResponse
    {
        $session = $this->cashier->current($request->user());

        return response()->json(['data' => $session ? $this->present($session, $request) : null]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'opening' => ['nullable', 'array'],
            'opening.*' => ['decimal:0,2', 'min:0', 'max:99999999'],
        ]);
        $branches = $request->user()->accessibleBranches();
        $branch = isset($data['branch_id']) ? $branches->firstWhere('id', (int) $data['branch_id']) : $branches->first();
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }
        $session = $this->cashier->open($request->user(), $branch->id, $data['opening'] ?? []);

        return response()->json(['data' => $this->present($session, $request)], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['counted' => ['required', 'array'], 'counted.*' => ['decimal:0,2', 'min:0', 'max:99999999'], 'notes' => ['nullable', 'string', 'max:1000']],
            ['counted.required' => 'Type the cash you counted in the drawer.', 'counted.*.decimal' => 'Type the amount in numbers, like 1500 or 1500.50.'],
        );
        $session = $this->cashier->current($request->user());
        if (! $session) {
            throw ValidationException::withMessages(['shift' => 'You have no open cashier shift.']);
        }

        return response()->json(['data' => $this->present($this->cashier->close($session, $data['counted'], $data['notes'] ?? null), $request)]);
    }

    /** Shifts list (mine, or everyone's with cashier.view_all) — also the cashier report. */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $all = $user->hasPermission('cashier.view_all');
        $hotel = $user->hotel;
        $query = CashierSession::with(['user:id,name', 'approver:id,name', 'branch:id,name', 'balances'])
            ->whereIn('branch_id', $user->accessibleBranches()->pluck('id'))
            ->when(! $all, fn ($q) => $q->where('user_id', $user->id))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('user_id') && $all, fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->boolean('short'), fn ($q) => $q->whereHas('balances', fn ($b) => $b->where('difference', '<', 0)))
            ->when($request->input('from'), fn ($q, $d) => $q->where('opened_at', '>=', HotelClock::parse($d, $hotel)))
            ->when($request->input('to'), fn ($q, $d) => $q->where('opened_at', '<', HotelClock::parse($d, $hotel)->addDay()));
        ListQuery::sort($query, $request, ['opened' => 'opened_at', 'closed' => 'closed_at', 'status' => 'status', 'person' => 'user_id'], 'opened');
        if (ListQuery::wantsFile($request)) {
            $money = fn (CashierSession $s, string $field) => $s->balances->map(fn ($b) => $b->currency.' '.$b->{$field})->implode(', ');

            return ListQuery::file($request, $query, 'Cashier shifts', ['Person', 'Branch', 'Opened', 'Closed', 'Status', 'Expected', 'Counted', 'Difference', 'Approved by', 'Note'], fn (CashierSession $s) => [
                $s->user?->name, $s->branch?->name, HotelClock::local($s->opened_at, $hotel)->format('Y-m-d H:i'), $s->closed_at ? HotelClock::local($s->closed_at, $hotel)->format('Y-m-d H:i') : '',
                $s->status, $money($s, 'expected_amount'), $money($s, 'counted_amount'), $money($s, 'difference'), $s->approver?->name, $s->notes,
            ]);
        }
        $page = $query->paginate(ListQuery::perPage($request));
        $page->getCollection()->transform(fn (CashierSession $s) => $this->summary($s, $request));

        return response()->json($page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request, $id), $request)]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->cashier->approve($this->find($request, $id), $request->user()), $request)]);
    }

    private function find(Request $request, string $id): CashierSession
    {
        $user = $request->user();

        return CashierSession::whereIn('branch_id', $user->accessibleBranches()->pluck('id'))
            ->when(! $user->hasPermission('cashier.view_all'), fn ($q) => $q->where('user_id', $user->id))
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function summary(CashierSession $s, Request $request): array
    {
        $hotel = $request->user()->hotel;

        return [
            'id' => $s->id, 'status' => $s->status, 'user' => $s->user?->only(['id', 'name']), 'branch' => $s->branch?->only(['id', 'name']),
            'opened_at' => HotelClock::iso($s->opened_at, $hotel), 'closed_at' => HotelClock::iso($s->closed_at, $hotel),
            'approved_by' => $s->approver?->name, 'approved_at' => HotelClock::iso($s->approved_at, $hotel), 'notes' => $s->notes,
            'balances' => $s->balances->map(fn (CashierSessionBalance $b) => $b->only(['currency', 'opening_amount', 'expected_amount', 'counted_amount', 'difference']))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(CashierSession $s, Request $request): array
    {
        $s->loadMissing(['user:id,name', 'approver:id,name', 'branch:id,name', 'balances']);
        $hotel = $request->user()->hotel;
        $payments = Payment::with(['method:id,name', 'reservation:id,code'])->where('cashier_session_id', $s->id)->latest('paid_at')->get();

        return $this->summary($s, $request) + [
            'expected' => $this->cashier->expected($s),
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => $p->id, 'type' => $p->type, 'amount' => $p->amount, 'currency' => $p->currency, 'method' => $p->method?->name,
                'reservation' => $p->reservation?->only(['id', 'code']), 'paid_at' => HotelClock::iso($p->paid_at, $hotel),
            ])->values(),
        ];
    }
}

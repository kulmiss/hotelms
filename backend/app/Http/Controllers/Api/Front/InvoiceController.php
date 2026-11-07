<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Presenters\InvoicePresenter;
use App\Models\ChargeType;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\ReservationRoom;
use App\Services\Billing\InvoiceService;
use App\Services\Booking\ReservationService;
use App\Support\HotelClock;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Guest bills: extras, discounts, payments, split, void, print (screen-list.md section 6). */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse|Response
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $hotel = $request->user()->hotel;
        $q = trim((string) $request->input('q'));
        $query = Invoice::with(['guest:id,first_name,last_name,phone', 'reservation:id,code', 'branch:id,name'])
            ->whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))
            ->when($request->input('status'), fn ($w, $s) => $w->whereIn('status', explode(',', $s)))
            ->when($request->boolean('unpaid'), fn ($w) => $w->where('status', '!=', 'void')->where('balance', '>', 0))
            // Bill date (hotel days): ?from=2026-11-01&to=2026-11-07
            ->when($request->input('from'), fn ($w, $d) => $w->where('issued_at', '>=', HotelClock::parse($d, $hotel)))
            ->when($request->input('to'), fn ($w, $d) => $w->where('issued_at', '<', HotelClock::parse($d, $hotel)->addDay()))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', "%{$q}%")
                ->orWhereHas('reservation', fn ($r) => $r->where('code', 'like', "%{$q}%"))
                ->orWhereHas('guest', fn ($g) => $g->search($q))));
        ListQuery::sort($query, $request, [
            'issued' => 'issued_at', 'number' => 'number', 'status' => 'status', 'total' => 'total', 'balance' => 'balance',
            'guest' => fn ($w, $dir) => $w->orderBy(Guest::select('last_name')->whereColumn('guests.id', 'invoices.guest_id')->limit(1), $dir),
        ], 'issued');
        if (ListQuery::wantsFile($request)) {
            return ListQuery::file($request, $query, 'Bills', ['Bill', 'Date', 'Guest', 'Booking', 'Branch', 'Status', 'Total', 'Paid', 'Balance', 'Currency'], fn (Invoice $i) => [
                $i->number, HotelClock::local($i->issued_at, $hotel)->format('Y-m-d'), $i->guest?->full_name, $i->reservation?->code, $i->branch?->name, $i->status, $i->total, $i->paid_total, $i->balance, $i->currency,
            ]);
        }
        $page = $query->paginate(ListQuery::perPage($request));
        $page->getCollection()->transform(fn (Invoice $i) => InvoicePresenter::summary($i, $hotel));

        return response()->json($page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => InvoicePresenter::detail($this->find($request, $id), $request->user()->hotel)]);
    }

    public function chargeTypes(): JsonResponse
    {
        return response()->json(['data' => ChargeType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_price', 'currency'])]);
    }

    public function addCharge(Request $request, string $id): JsonResponse
    {
        $invoice = $this->find($request, $id);
        $data = $request->validate([
            'type' => ['required', Rule::in(['extra', 'discount'])],
            'charge_type_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:200'],
            'quantity' => ['nullable', 'decimal:0,2', 'gt:0', 'max:9999'],
            'unit_price' => ['nullable', 'required_if:type,extra', 'decimal:0,2', 'min:0', 'max:99999999'],
            'discount_percent' => ['nullable', 'required_if:type,discount', 'decimal:0,2', 'gt:0', 'max:100'],
            'reservation_room_id' => ['nullable', 'integer'],
            'service_date' => ['nullable', 'date', 'after:-1 year', 'before:+1 year'],
        ], ['service_date.after' => 'Choose a date in the last year.', 'service_date.before' => 'Choose a date in the next year.']);
        if (! empty($data['reservation_room_id']) && ! ReservationRoom::where('reservation_id', $invoice->reservation_id)->whereKey($data['reservation_room_id'])->exists()) {
            throw ValidationException::withMessages(['reservation_room_id' => 'Choose a room of this booking.']);
        }
        $permission = $data['type'] === 'discount' ? 'invoices.discount' : 'invoices.add_charge';
        abort_unless($request->user()->hasPermission($permission), 403, 'You may not do this.');

        return response()->json(['data' => InvoicePresenter::detail($this->invoices->addCharge($invoice, $data, $request->user()), $request->user()->hotel)], 201);
    }

    public function removeItem(Request $request, string $id, string $item): JsonResponse
    {
        $invoice = $this->find($request, $id);
        $line = InvoiceItem::where('invoice_id', $invoice->id)->findOrFail($item);
        // Removing an early/late fee is waiving it: the same permission as waiving at the desk.
        $permission = match ($line->type) {
            'discount' => 'invoices.discount',
            'early_check_in', 'late_check_out', 'early_check_out' => 'fees.waive',
            default => 'invoices.add_charge',
        };
        abort_unless($request->user()->hasPermission($permission), 403, 'You may not do this.');

        return response()->json(['data' => InvoicePresenter::detail($this->invoices->removeLine($invoice, $line), $request->user()->hotel)]);
    }

    public function payment(Request $request, string $id, ReservationService $reservations): JsonResponse
    {
        $invoice = $this->find($request, $id);
        $this->invoices->assertEditable($invoice);
        $data = $request->validate([
            'type' => ['required', Rule::in(['payment', 'refund'])],
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:100'],
            'proof_id' => ['nullable', 'integer'],
            'refund_reason' => ['nullable', 'required_if:type,refund', 'string', 'max:200'],
            'confirm_overpay' => ['nullable', 'boolean'], // yes, more than the balance
        ]);
        abort_unless($request->user()->hasPermission($data['type'] === 'refund' ? 'payments.refund' : 'payments.take'), 403, 'You may not do this.');
        if (! $invoice->reservation) {
            throw ValidationException::withMessages(['invoice' => 'This invoice has no booking.']);
        }
        $reservations->recordPayment($invoice->reservation, $data, $request->user(), $invoice);

        return response()->json(['data' => InvoicePresenter::detail($invoice->fresh(), $request->user()->hotel)], 201);
    }

    /** The company that pays (name + TIN on the printed invoice); empty both to remove it. Also on a paid bill. */
    public function buyer(Request $request, string $id): JsonResponse
    {
        $invoice = $this->find($request, $id);
        abort_if($invoice->status === 'void', 422, 'This bill is cancelled.');
        $data = $request->validate([
            'company' => ['nullable', 'required_with:tin', 'string', 'max:150'],
            'tin' => ['nullable', 'string', 'regex:/^[0-9]{6,15}$/'],
        ], ['tin.regex' => 'The TIN has only numbers (usually 10).', 'company.required_with' => 'Type the name of the company for this TIN.']);
        $invoice->update(['buyer_company' => $data['company'] ?: null, 'buyer_tin' => $data['tin'] ?: null]);

        return response()->json(['data' => InvoicePresenter::detail($invoice->fresh(), $request->user()->hotel)]);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $invoice = $this->find($request, $id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => InvoicePresenter::detail($this->invoices->void($invoice, $data['reason'], $request->user()), $request->user()->hotel)]);
    }

    public function split(Request $request, string $id): JsonResponse
    {
        $result = $this->invoices->split($this->find($request, $id), $request->user());

        return response()->json(['data' => array_map(fn (Invoice $i) => InvoicePresenter::summary($i, $request->user()->hotel), $result)]);
    }

    public function payments(Request $request): JsonResponse|Response
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $hotel = $request->user()->hotel;
        $query = Payment::with(['method:id,name', 'receiver:id,name', 'reservation:id,code,guest_id', 'reservation.guest:id,first_name,last_name', 'invoice:id,number'])
            ->whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))
            ->when($request->input('type'), fn ($w, $t) => $w->where('type', $t))
            ->when($request->input('from'), fn ($w, $d) => $w->where('paid_at', '>=', HotelClock::parse($d, $hotel)))
            ->when($request->input('to'), fn ($w, $d) => $w->where('paid_at', '<', HotelClock::parse($d, $hotel)->addDay()));
        ListQuery::sort($query, $request, ['paid' => 'paid_at', 'amount' => 'amount_in_base', 'type' => 'type'], 'paid');
        if (ListQuery::wantsFile($request)) {
            return ListQuery::file($request, $query, 'Payments', ['Date', 'Type', 'Amount', 'Currency', 'In main currency', 'Method', 'Reference', 'Booking', 'Guest', 'Bill', 'Taken by'], fn (Payment $p) => [
                HotelClock::local($p->paid_at, $hotel)->format('Y-m-d H:i'), $p->type, $p->amount, $p->currency, $p->amount_in_base, $p->method?->name, $p->reference,
                $p->reservation?->code, $p->reservation?->guest?->full_name, $p->invoice?->number, $p->receiver?->name,
            ]);
        }
        $page = $query->paginate(ListQuery::perPage($request));
        $page->getCollection()->transform(fn (Payment $p) => [
            'id' => $p->id, 'type' => $p->type, 'amount' => $p->amount, 'currency' => $p->currency, 'amount_in_base' => $p->amount_in_base,
            'method' => $p->method?->name, 'reference' => $p->reference, 'by' => $p->receiver?->name, 'paid_at' => HotelClock::iso($p->paid_at, $hotel),
            'reservation' => $p->reservation ? ['id' => $p->reservation->id, 'code' => $p->reservation->code, 'guest' => $p->reservation->guest?->full_name] : null,
            'invoice' => $p->invoice ? ['id' => $p->invoice->id, 'number' => $p->invoice->number] : null,
        ]);

        return response()->json($page);
    }

    private function find(Request $request, string $id): Invoice
    {
        return Invoice::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }
}

<?php

namespace App\Services\Billing;

use App\Models\ChargeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\NumberSequence;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\HotelSettings;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guest bills (database-schema.md section 7, workflows.md W4–W6).
 * One invoice per reservation by default; it can be split into one per room.
 * Totals: subtotal (all charges) − discounts + added taxes = total; total − payments = balance.
 */
class InvoiceService
{
    public function __construct(private TaxCalculator $taxes, private HotelSettings $settings) {}

    /** The reservation's open bill (created at check-in with the room charges). */
    public function openFor(Reservation $reservation, User $user): Invoice
    {
        if ($existing = $reservation->invoices()->where('status', '!=', 'void')->orderBy('id')->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($reservation, $user) {
            $invoice = Invoice::create([
                'branch_id' => $reservation->branch_id,
                'reservation_id' => $reservation->id,
                'guest_id' => $reservation->guest_id,
                'number' => $this->number($reservation),
                'currency' => $reservation->currency,
                'status' => 'open',
                'issued_at' => now(),
            ]);

            foreach ($reservation->rooms()->with(['rates', 'roomType', 'room'])->get() as $rr) {
                foreach ($rr->rates as $rate) {
                    $this->line($invoice, [
                        'type' => 'room',
                        'description' => trim(($rr->room ? 'Room '.$rr->room->number.' · ' : '').($rr->roomType?->name ?? '').' · '.$rate->label),
                        'unit_price' => (string) $rate->amount,
                        'service_date' => $rate->date->toDateString(),
                        'reservation_room_id' => $rr->id,
                    ], $user);
                }
            }
            if (Money::isPositive((string) $reservation->discount_amount)) {
                $this->line($invoice, [
                    'type' => 'discount',
                    'description' => 'Discount '.rtrim(rtrim((string) $reservation->discount_percent, '0'), '.').'%',
                    'unit_price' => Money::sub('0', (string) $reservation->discount_amount),
                    'discount_percent' => (string) $reservation->discount_percent,
                    'service_date' => HotelClock::local($reservation->check_in_at, $reservation->hotel)->toDateString(),
                ], $user);
            }
            // Deposits taken before arrival, and payments of a voided earlier bill, now count on this bill.
            $voided = $reservation->invoices()->where('status', 'void')->pluck('id');
            Payment::where('reservation_id', $reservation->id)
                ->where(fn ($q) => $q->whereNull('invoice_id')->orWhereIn('invoice_id', $voided))
                ->update(['invoice_id' => $invoice->id]);
            foreach ($voided as $id) {
                $this->recalc(Invoice::find($id));
            }

            return $this->recalc($invoice);
        });
    }

    /**
     * Add one line (with its tax snapshot).
     *
     * @param  array<string, mixed>  $data
     */
    public function line(Invoice $invoice, array $data, ?User $user): InvoiceItem
    {
        $qty = (string) ($data['quantity'] ?? '1');
        $unit = Money::round((string) $data['unit_price']);
        $waived = (bool) ($data['is_waived'] ?? false);
        $amount = $waived ? '0.00' : Money::mul($unit, $qty);

        $item = $invoice->items()->create([
            'type' => $data['type'],
            'description' => mb_substr((string) $data['description'], 0, 200),
            'quantity' => $qty,
            'unit_price' => $unit,
            'amount' => $amount,
            'discount_percent' => $data['discount_percent'] ?? null,
            'is_waived' => $waived,
            'waived_by' => $waived ? $user?->id : null,
            'reservation_room_id' => $data['reservation_room_id'] ?? null,
            'charge_type_id' => $data['charge_type_id'] ?? null,
            'service_date' => $data['service_date'] ?? HotelClock::now($invoice->hotel)->toDateString(),
            'created_by' => $user?->id,
        ]);
        foreach ($this->taxes->forLine($item->type, $amount) as $tax) {
            $item->taxes()->create($tax);
        }

        return $item;
    }

    /**
     * An extra charge added by hand (laundry, food…), or a discount within the role's limit.
     *
     * @param  array<string, mixed>  $data
     */
    public function addCharge(Invoice $invoice, array $data, User $user): Invoice
    {
        $this->assertEditable($invoice);

        if (($data['type'] ?? 'extra') === 'discount') {
            $percent = Money::round((string) $data['discount_percent']);
            $max = (string) ($user->role?->max_discount_percent ?? '0');
            if (Money::compare($percent, $max) > 0) {
                throw ValidationException::withMessages(['discount_percent' => "Your role allows discounts up to {$max}%."]);
            }
            $base = Money::sum($invoice->items()->whereIn('type', ['room', 'extra', 'early_check_in', 'late_check_out'])->pluck('amount')->map(fn ($v) => (string) $v));
            // All discounts on one bill together stay within the role's limit (no stacking 5% + 5% + ...).
            $given = Money::sub('0', Money::sum($invoice->items()->where('type', 'discount')->pluck('amount')->map(fn ($v) => (string) $v)));
            $new = Money::percentOf($base, $percent);
            if (Money::compare(Money::add($given, $new), Money::percentOf($base, $max)) > 0) {
                throw ValidationException::withMessages(['discount_percent' => "All discounts on this bill together can be at most {$max}% for your role."]);
            }
            if (! Money::isPositive($base)) {
                throw ValidationException::withMessages(['discount_percent' => 'There is nothing to discount on this bill yet.']);
            }
            $this->line($invoice, [
                'type' => 'discount',
                'description' => trim(($data['description'] ?? '') ?: 'Discount '.rtrim(rtrim($percent, '0'), '.').'%'),
                'unit_price' => Money::sub('0', Money::percentOf($base, $percent)),
                'discount_percent' => $percent,
            ], $user);

            return $this->recalc($invoice);
        }

        $chargeType = isset($data['charge_type_id']) ? ChargeType::find($data['charge_type_id']) : null;
        $this->line($invoice, [
            'type' => 'extra',
            'description' => ($data['description'] ?? '') ?: ($chargeType?->name ?? 'Extra'),
            'quantity' => (string) ($data['quantity'] ?? '1'),
            'unit_price' => (string) ($data['unit_price'] ?? $chargeType?->default_price ?? '0'),
            'charge_type_id' => $chargeType?->id,
            'reservation_room_id' => $data['reservation_room_id'] ?? null,
            'service_date' => $data['service_date'] ?? HotelClock::now($invoice->hotel)->toDateString(),
        ], $user);

        return $this->recalc($invoice);
    }

    /** Remove a line added by mistake (not room nights; those follow the stay). */
    public function removeLine(Invoice $invoice, InvoiceItem $item): Invoice
    {
        $this->assertEditable($invoice);
        if ($item->type === 'room') {
            throw ValidationException::withMessages(['item' => 'Room nights change with the stay (extend, move or shorten it).']);
        }
        $item->delete();

        return $this->recalc($invoice);
    }

    /** Totals, paid amount, balance and status from the lines and payments. */
    public function recalc(Invoice $invoice): Invoice
    {
        $items = $invoice->items()->with('taxes')->get();
        $subtotal = Money::sum($items->where('type', '!=', 'discount')->pluck('amount')->map(fn ($v) => (string) $v));
        $discount = Money::sub('0', Money::sum($items->where('type', 'discount')->pluck('amount')->map(fn ($v) => (string) $v)));
        $tax = Money::sum($items->flatMap(fn (InvoiceItem $i) => $i->taxes->where('is_inclusive', false)->pluck('amount'))->map(fn ($v) => (string) $v));
        $total = Money::add(Money::sub($subtotal, $discount), $tax);

        $payments = Payment::where('invoice_id', $invoice->id)->get(['type', 'amount_in_base']);
        $in = Money::sum($payments->where('type', '!=', 'refund')->pluck('amount_in_base')->map(fn ($v) => (string) $v));
        $out = Money::sum($payments->where('type', 'refund')->pluck('amount_in_base')->map(fn ($v) => (string) $v));
        $paid = Money::sub($in, $out);
        $balance = Money::sub($total, $paid);

        $status = $invoice->status;
        if ($status !== 'void') {
            $status = match (true) {
                Money::compare($balance, '0') <= 0 => 'paid',
                $invoice->outstanding_approved_at !== null => 'outstanding',
                Money::isPositive($paid) => 'partially_paid',
                default => 'open',
            };
        }

        $invoice->update(['subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax, 'total' => $total, 'paid_total' => $paid, 'balance' => $balance, 'status' => $status]);

        return $invoice->fresh();
    }

    /** Void (managers, reason required). Payments stay recorded; refund them separately. */
    public function void(Invoice $invoice, string $reason, User $user): Invoice
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['invoice' => 'Already void.']);
        }
        $invoice->update(['status' => 'void', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);

        return $invoice->fresh();
    }

    /** One invoice per room (group bookings). Payments stay on the first invoice. */
    public function split(Invoice $invoice, User $user): array
    {
        $this->assertEditable($invoice);
        $reservation = $invoice->reservation;
        $rooms = $reservation?->rooms()->orderBy('id')->get() ?? collect();
        if ($rooms->count() < 2) {
            throw ValidationException::withMessages(['invoice' => 'Only bookings with several rooms can be split.']);
        }
        if ($invoice->reservation_room_id) {
            throw ValidationException::withMessages(['invoice' => 'This bill is already for one room.']);
        }

        return DB::transaction(function () use ($invoice, $rooms, $reservation, $user) {
            $first = $rooms->first();
            $invoice->update(['reservation_room_id' => $first->id]);
            $result = [$invoice];
            foreach ($rooms->slice(1) as $rr) {
                /** @var ReservationRoom $rr */
                $new = Invoice::create([
                    'branch_id' => $invoice->branch_id, 'reservation_id' => $reservation->id, 'reservation_room_id' => $rr->id,
                    'guest_id' => $invoice->guest_id, 'number' => $this->number($reservation), 'currency' => $invoice->currency,
                    'status' => 'open', 'issued_at' => now(),
                ]);
                InvoiceItem::where('invoice_id', $invoice->id)->where('reservation_room_id', $rr->id)->update(['invoice_id' => $new->id]);
                $result[] = $new;
            }
            // Discounts of the whole booking are shared by room value.
            $this->spreadDiscounts($invoice, collect($result)->slice(1)->all(), $user);

            return array_map(fn (Invoice $i) => $this->recalc($i), $result);
        });
    }

    /** A manager allows check-out with money still owed; the bill stays "outstanding" (Q8). */
    public function approveBalance(Invoice $invoice, User $user): Invoice
    {
        $invoice->update(['outstanding_approved_by' => $user->id, 'outstanding_approved_at' => now()]);

        return $this->recalc($invoice);
    }

    public function assertEditable(Invoice $invoice): void
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is void.']);
        }
    }

    // ------------------------------------------------------------------ internals

    private function number(Reservation $reservation): string
    {
        $hotel = $reservation->hotel;
        $prefix = strtoupper((string) ($this->settings->get('invoice_prefix') ?: 'INV'));
        $year = HotelClock::now($hotel)->format('Y');

        return sprintf('%s-%s-%05d', $prefix, $year, NumberSequence::next("invoice-{$year}"));
    }

    /**
     * @param  list<Invoice>  $others
     */
    private function spreadDiscounts(Invoice $from, array $others, User $user): void
    {
        $discounts = $from->items()->where('type', 'discount')->get();
        if ($discounts->isEmpty()) {
            return;
        }
        $roomValue = fn (Invoice $i) => Money::sum($i->items()->where('type', '!=', 'discount')->pluck('amount')->map(fn ($v) => (string) $v));
        $all = array_merge([$from], $others);
        $values = array_map($roomValue, $all);
        $grand = Money::sum($values);
        if (! Money::isPositive($grand)) {
            return;
        }

        foreach ($discounts as $d) {
            $total = (string) $d->amount; // negative
            $given = '0.00';
            foreach ($others as $k => $inv) {
                $share = Money::round(bcdiv(bcmul($total, $values[$k + 1], 6), $grand, 6));
                $given = Money::add($given, $share);
                $this->line($inv, ['type' => 'discount', 'description' => $d->description, 'unit_price' => $share, 'discount_percent' => $d->discount_percent, 'service_date' => $d->service_date->toDateString()], $user);
            }
            $rest = Money::sub($total, $given);
            $d->taxes()->delete();
            $d->update(['unit_price' => $rest, 'amount' => $rest]);
            foreach ($this->taxes->forLine('discount', $rest) as $tax) {
                $d->taxes()->create($tax);
            }
        }
    }
}

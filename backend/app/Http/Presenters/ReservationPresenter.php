<?php

namespace App\Http\Presenters;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Support\HotelClock;
use App\Support\Money;

/** JSON shape of a reservation (times in the hotel's own time zone). */
class ReservationPresenter
{
    /**
     * Adds the bills' totals to a reservation query (one question for a whole list), so lists show the
     * real total: rooms plus extra charges, fees and extensions, and everything paid.
     */
    public static function withBills($query)
    {
        $live = fn ($q) => $q->where('status', '!=', 'void');

        return $query->withCount(['invoices as bills_count' => $live])
            ->withSum(['invoices as bills_total' => $live], 'total')
            ->withSum(['invoices as bills_paid' => $live], 'paid_total');
    }

    /**
     * Total and paid of a stay. Once there is a bill, the bills are the truth (extras such as breakfast,
     * fees, extensions); before that, the booking.
     *
     * @return array{0: string, 1: string}
     */
    public static function money(Reservation $r): array
    {
        $attrs = $r->getAttributes();
        if (array_key_exists('bills_count', $attrs)) {
            return (int) $attrs['bills_count'] > 0
                ? [Money::round((string) $attrs['bills_total']), Money::round((string) $attrs['bills_paid'])]
                : [(string) $r->total, (string) $r->paid_total];
        }
        if ($r->relationLoaded('invoices')) {
            $bills = $r->invoices->where('status', '!=', 'void');
            if ($bills->isNotEmpty()) {
                return [Money::sum($bills->pluck('total')->map(fn ($v) => (string) $v)->all()), Money::sum($bills->pluck('paid_total')->map(fn ($v) => (string) $v)->all())];
            }
        }

        return [(string) $r->total, (string) $r->paid_total];
    }

    /**
     * What the guest still owes (negative = money to give back). Once there is a bill, the bills;
     * a cancelled or no-show booking owes nothing more (what was paid above the fee goes back); otherwise the booking total.
     */
    public static function balance(Reservation $r): string
    {
        $bills = $r->invoices->where('status', '!=', 'void');
        if ($bills->isNotEmpty()) {
            return Money::sum($bills->pluck('balance')->map(fn ($v) => (string) $v)->all());
        }
        // Cancelled / no-show: the hotel keeps up to the fee; the rest goes back. An unpaid fee is not a debt (decision 8).
        if (in_array($r->status, ['cancelled', 'no_show'], true)) {
            return Money::min(Money::sub((string) ($r->cancellation_fee ?? '0'), (string) $r->paid_total), '0.00');
        }

        return Money::sub((string) $r->total, (string) $r->paid_total);
    }

    /** @return array<string, mixed> */
    public static function summary(Reservation $r, Hotel $hotel): array
    {
        return [
            'id' => $r->id,
            'code' => $r->code,
            'status' => $r->status,
            'stay_type' => $r->stay_type,
            'open_stay' => (bool) $r->open_stay,
            'source' => $r->source,
            'branch' => $r->branch?->only(['id', 'name']),
            'booked_at' => HotelClock::iso($r->created_at, $hotel),
            'guest' => $r->guest ? ['id' => $r->guest->id, 'name' => $r->guest->full_name, 'phone' => $r->guest->phone] : null,
            'check_in_at' => HotelClock::iso($r->check_in_at, $hotel),
            'check_out_at' => HotelClock::iso($r->check_out_at, $hotel),
            'expires_at' => HotelClock::iso($r->expires_at, $hotel),
            'rooms' => $r->rooms->map(fn (ReservationRoom $rr) => ['room_type' => $rr->roomType?->name, 'room_number' => $rr->room?->number])->values(),
            'adults' => $r->adults,
            'children' => $r->children,
            'currency' => $r->currency,
            'total' => self::money($r)[0],
            'paid_total' => self::money($r)[1],
            'booking_total' => $r->total, // rooms only, as booked (the price breakdown)
            'deposit_required' => $r->deposit_required,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Reservation $r, Hotel $hotel): array
    {
        $r->load(['guest', 'branch:id,name,code', 'rooms.roomType:id,name', 'rooms.room:id,number', 'rooms.rates', 'rooms.guests:id,first_name,last_name', 'payments.method:id,name', 'payments.receiver:id,name', 'payments.proof', 'creator:id,name', 'payLaterBy:id,name', 'invoices']);

        return array_merge(self::summary($r, $hotel), [
            'guest' => $r->guest,
            'notes' => $r->notes,
            'subtotal' => $r->subtotal,
            'discount_percent' => $r->discount_percent,
            'discount_amount' => $r->discount_amount,
            'tax_total' => $r->tax_total,
            // Once there is a bill, it is the truth (extras, fees, extensions); before that the booking total.
            'balance' => self::balance($r),
            'cancellation_fee' => $r->cancellation_fee,
            'cancellation_policy_id' => $r->cancellation_policy_id,
            'policy' => $r->policy_snapshot,
            'cancelled_at' => HotelClock::iso($r->cancelled_at, $hotel),
            'cancel_reason' => $r->cancel_reason,
            'created_by' => $r->creator?->name,
            'created_at' => HotelClock::iso($r->created_at, $hotel),
            // Allowed to pay everything at check-out (no deposit or up-front payment), and by whom.
            'pay_later' => $r->pay_later_at ? ['by' => $r->payLaterBy?->name, 'at' => HotelClock::iso($r->pay_later_at, $hotel)] : null,
            'rooms' => $r->rooms->map(fn (ReservationRoom $rr) => [
                'id' => $rr->id,
                'room_type_id' => $rr->room_type_id,
                'room_type' => $rr->roomType?->name,
                'room_id' => $rr->room_id,
                'room_number' => $rr->room?->number,
                'adults' => $rr->adults,
                'children' => $rr->children,
                'total' => $rr->total_amount,
                'status' => $rr->status,
                'lines' => $rr->rates->map(fn ($l) => ['date' => $l->date->toDateString(), 'label' => $l->label, 'amount' => $l->amount, 'rule_name' => $l->rule_name])->values(),
                'guests' => $rr->guests->map(fn ($g) => ['id' => $g->id, 'name' => $g->full_name])->values(),
            ])->values(),
            'invoices' => $r->invoices->map(fn (Invoice $i) => $i->only(['id', 'number', 'status', 'total', 'paid_total', 'balance', 'reservation_room_id']))->values(),
            'payments' => $r->payments->sortByDesc('paid_at')->map(fn (Payment $p) => [
                'id' => $p->id, 'type' => $p->type, 'amount' => $p->amount, 'currency' => $p->currency, 'amount_in_base' => $p->amount_in_base,
                'method' => $p->method?->name, 'reference' => $p->reference, 'refund_reason' => $p->refund_reason,
                'paid_at' => HotelClock::iso($p->paid_at, $hotel), 'by' => $p->receiver?->name,
                'proof' => $p->proof ? ['id' => $p->proof->id, 'mime' => $p->proof->mime] : null,
            ])->values(),
        ]);
    }
}

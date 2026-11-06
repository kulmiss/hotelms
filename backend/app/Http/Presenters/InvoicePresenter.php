<?php

namespace App\Http\Presenters;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\HotelSettings;
use App\Support\HotelClock;

/** JSON shape of an invoice, with what the print layout needs. */
class InvoicePresenter
{
    /** @return array<string, mixed> */
    public static function summary(Invoice $i, Hotel $hotel): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'reservation' => $i->reservation ? ['id' => $i->reservation->id, 'code' => $i->reservation->code] : null,
            'guest' => $i->guest ? ['id' => $i->guest->id, 'name' => $i->guest->full_name, 'phone' => $i->guest->phone] : null,
            'branch' => $i->branch?->only(['id', 'name']),
            'currency' => $i->currency,
            'total' => $i->total,
            'paid_total' => $i->paid_total,
            'balance' => $i->balance,
            'issued_at' => HotelClock::iso($i->issued_at, $hotel),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Invoice $i, Hotel $hotel): array
    {
        $i->load(['items.taxes', 'payments.method:id,name', 'payments.receiver:id,name', 'reservation.rooms.room:id,number', 'reservation.rooms.roomType:id,name', 'guest', 'branch']);
        $taxes = [];
        foreach ($i->items as $item) {
            foreach ($item->taxes as $tax) {
                $key = $tax->name.'|'.$tax->rate.'|'.(int) $tax->is_inclusive;
                $taxes[$key] ??= ['name' => $tax->name, 'rate' => $tax->rate, 'is_inclusive' => $tax->is_inclusive, 'amount' => '0.00'];
                $taxes[$key]['amount'] = bcadd($taxes[$key]['amount'], (string) $tax->amount, 2);
            }
        }
        $invoiceSettings = app(HotelSettings::class)->group(HotelSettings::INVOICE_DEFAULTS);

        return array_merge(self::summary($i, $hotel), [
            'subtotal' => $i->subtotal,
            'discount_total' => $i->discount_total,
            'tax_total' => $i->tax_total,
            'reservation_room_id' => $i->reservation_room_id,
            'guest' => $i->guest,
            'outstanding_approved_at' => HotelClock::iso($i->outstanding_approved_at, $hotel),
            'voided_at' => HotelClock::iso($i->voided_at, $hotel),
            'void_reason' => $i->void_reason,
            'stay' => $i->reservation ? [
                'check_in_at' => HotelClock::iso($i->reservation->check_in_at, $hotel),
                'check_out_at' => HotelClock::iso($i->reservation->check_out_at, $hotel),
                'status' => $i->reservation->status,
                'rooms' => $i->reservation->rooms->map(fn ($rr) => ['id' => $rr->id, 'room_number' => $rr->room?->number, 'room_type' => $rr->roomType?->name])->values(),
            ] : null,
            'items' => $i->items->map(fn (InvoiceItem $it) => [
                'id' => $it->id, 'type' => $it->type, 'description' => $it->description, 'quantity' => $it->quantity, 'unit_price' => $it->unit_price,
                'amount' => $it->amount, 'is_waived' => $it->is_waived, 'service_date' => $it->service_date->toDateString(), 'reservation_room_id' => $it->reservation_room_id,
            ])->values(),
            'taxes' => array_values($taxes),
            'payments' => $i->payments->sortBy('paid_at')->map(fn (Payment $p) => [
                'id' => $p->id, 'type' => $p->type, 'amount' => $p->amount, 'currency' => $p->currency, 'amount_in_base' => $p->amount_in_base,
                'method' => $p->method?->name, 'reference' => $p->reference, 'refund_reason' => $p->refund_reason,
                'paid_at' => HotelClock::iso($p->paid_at, $hotel), 'by' => $p->receiver?->name,
            ])->values(),
            'hotel' => [
                'name' => $hotel->name, 'legal_name' => $hotel->legal_name, 'tin' => $hotel->tin, 'vat_number' => $hotel->vat_number,
                'address' => $hotel->address, 'city' => $hotel->city, 'phone' => $hotel->phone, 'email' => $hotel->email,
                'logo_url' => $hotel->logo_path ? '/storage/'.$hotel->logo_path : null,
                'footer' => $invoiceSettings['invoice_footer'],
            ],
        ]);
    }
}

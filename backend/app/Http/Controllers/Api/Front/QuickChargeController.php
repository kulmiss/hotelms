<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Models\ChargeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\Billing\InvoiceService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quick charges (owner, 2026-11-07): breakfast, laundry, taxi, spa… on a guest's room in one or two taps.
 * Used from the room board and the "Charge a room" screen (reception and the Restaurant / Bar role).
 * The guest's own bill gets an extra line, like "Add charge" on the bill.
 */
class QuickChargeController extends Controller
{
    /** Minutes in which the person who added a quick charge can undo it. */
    public const UNDO_MINUTES = 10;

    public function __construct(private InvoiceService $invoices) {}

    /** Rooms with a guest in them now (one branch) and the hotel's quick buttons. */
    public function index(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $stays = ReservationRoom::with(['room:id,number,floor', 'roomType:id,name', 'reservation:id,code,guest_id', 'reservation.guest:id,first_name,last_name'])
            ->where('status', 'checked_in')->whereNotNull('room_id')
            ->whereHas('reservation', fn ($q) => $q->where('branch_id', $branch->id)->where('status', 'checked_in'))
            ->get()
            ->sortBy(fn (ReservationRoom $rr) => [strlen((string) $rr->room?->number), (string) $rr->room?->number])
            ->values();

        return response()->json([
            'rooms' => $stays->map(fn (ReservationRoom $rr) => [
                'room_id' => $rr->room_id, 'number' => $rr->room?->number, 'floor' => $rr->room?->floor, 'room_type' => $rr->roomType?->name,
                'reservation_id' => $rr->reservation_id, 'code' => $rr->reservation?->code,
                // First name and the first letter of the last name: enough to check it is the right guest.
                'guest' => trim(($rr->reservation?->guest?->first_name ?? '').' '.mb_substr((string) $rr->reservation?->guest?->last_name, 0, 1)),
            ]),
            'buttons' => self::buttons(),
        ]);
    }

    /** @return list<array{id: int, name: string, price: ?string, currency: string}> */
    public static function buttons(): array
    {
        return ChargeType::where('is_active', true)->where('is_quick', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (ChargeType $c) => ['id' => $c->id, 'name' => $c->name, 'price' => $c->default_price !== null ? (string) $c->default_price : null, 'currency' => $c->currency])
            ->all();
    }

    /** Add a charge to the guest staying in this room. A charge type without a price (taxi) needs the amount. */
    public function store(Request $request, string $roomId): JsonResponse
    {
        $data = $request->validate([
            'charge_type_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'required_without:charge_type_id', 'string', 'max:200'],
            'unit_price' => ['nullable', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'quantity' => ['nullable', 'integer', 'between:1,99'],
        ]);
        $room = Room::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($roomId);
        $type = isset($data['charge_type_id']) ? ChargeType::where('is_active', true)->findOrFail($data['charge_type_id']) : null;
        $price = $data['unit_price'] ?? ($type?->default_price !== null ? (string) $type->default_price : null);
        if ($price === null || ! Money::isPositive((string) $price)) {
            throw ValidationException::withMessages(['unit_price' => 'Type the amount.']);
        }

        $item = DB::transaction(function () use ($room, $type, $data, $price, $request) {
            $stay = ReservationRoom::with('reservation')->where('room_id', $room->id)->where('status', 'checked_in')->lockForUpdate()->first();
            if (! $stay || $stay->reservation?->status !== 'checked_in') {
                throw ValidationException::withMessages(['room' => "Nobody is staying in room {$room->number} now."]);
            }
            // The bill of this room when the bill was split per room, else the booking's bill.
            $invoice = Invoice::where('reservation_id', $stay->reservation_id)->where('status', '!=', 'void')->where('reservation_room_id', $stay->id)->first()
                ?? $this->invoices->openFor($stay->reservation, $request->user());
            $this->invoices->addCharge($invoice, [
                'type' => 'extra', 'charge_type_id' => $type?->id, 'description' => $data['description'] ?? null,
                'quantity' => (string) ($data['quantity'] ?? 1), 'unit_price' => (string) $price, 'reservation_room_id' => $stay->id,
            ], $request->user());

            return InvoiceItem::where('invoice_id', $invoice->id)->where('created_by', $request->user()->id)->latest('id')->firstOrFail();
        });

        return response()->json(['data' => [
            'id' => $item->id, 'room' => $room->number, 'description' => $item->description, 'quantity' => (string) $item->quantity,
            'amount' => (string) $item->amount, 'currency' => $item->invoice?->currency, 'reservation_id' => $item->invoice?->reservation_id,
        ]], 201);
    }

    /** Undo a quick charge: only the person who added it, only for a few minutes. */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        $item = InvoiceItem::where('type', 'extra')->where('created_by', $request->user()->id)
            ->where('created_at', '>=', now()->subMinutes(self::UNDO_MINUTES))->findOrFail($itemId);
        $this->invoices->removeLine($item->invoice, $item);

        return response()->json(['ok' => true]);
    }

    private function branch(Request $request)
    {
        $branches = $request->user()->accessibleBranches();
        $branch = $request->input('branch_id') ? $branches->firstWhere('id', (int) $request->input('branch_id')) : $branches->first();
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }

        return $branch;
    }
}

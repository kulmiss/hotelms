<?php

namespace App\Services\Automation;

use App\Mail\ReportMail;
use App\Models\Approval;
use App\Models\CashierSession;
use App\Models\Hotel;
use App\Models\HousekeepingTask;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Messages\GuestMessenger;
use App\Support\HotelClock;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Automations that react to something happening (a booking confirmed, a payment, a room cleaned…).
 * Called after the change is saved; a problem here never breaks the screen that made the change.
 */
class AutomationEvents
{
    /** @var array<int, Hotel> */
    private array $hotels = [];

    public function __construct(private Automations $a, private RuleEngine $rules, private TenantContext $tenant) {}

    /** Runs a reaction safely (errors are logged, never shown to the person working). */
    public function safely(?int $hotelId, callable $fn): void
    {
        if (! $hotelId) {
            return;
        }
        try {
            $hotel = $this->hotels[$hotelId] ??= Hotel::find($hotelId);
            if ($hotel && ! $hotel->is_demo) {
                $this->tenant->runAs($hotel, fn () => $fn($hotel));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ------------------------------------------------------------------ bookings

    public function reservationCreated(Reservation $r): void
    {
        $this->safely($r->hotel_id, function (Hotel $hotel) use ($r) {
            $this->rules->fire($hotel, 'booking_created', RuleEngine::bookingFacts($r, $hotel), $r);
            if ($r->status === 'confirmed') {
                $this->confirmed($hotel, $r);
            }
        });
    }

    public function reservationUpdated(Reservation $r): void
    {
        if (! $r->wasChanged('status')) {
            return;
        }
        $this->safely($r->hotel_id, function (Hotel $hotel) use ($r) {
            match ($r->status) {
                'confirmed' => $this->confirmed($hotel, $r),
                'checked_in' => $this->checkedIn($hotel, $r),
                'checked_out' => $this->checkedOut($hotel, $r),
                'cancelled', 'no_show' => $this->cancelled($hotel, $r),
                default => null,
            };
        });
    }

    private function confirmed(Hotel $hotel, Reservation $r): void
    {
        $this->rules->fire($hotel, 'booking_confirmed', RuleEngine::bookingFacts($r, $hotel), $r);
        // 1. Confirmation to the guest (Settings → Guest messages: email, SMS on paid plans).
        if ($r->guest && $this->a->enabled($hotel, 'booking_confirmation') && $this->a->once($hotel, 'booking_confirmation', "r{$r->id}")) {
            app(GuestMessenger::class)->sendAuto($hotel, $r, 'confirmation');
        }
    }

    private function checkedOut(Hotel $hotel, Reservation $r): void
    {
        $this->rules->fire($hotel, 'checked_out', RuleEngine::bookingFacts($r, $hotel), $r);
        // Thank-you with the feedback link: only when the guest said yes.
        if ($r->guest && $r->feedback_consent && $this->a->enabled($hotel, 'guest_thank_you') && $this->a->once($hotel, 'guest_thank_you', "r{$r->id}")) {
            app(GuestMessenger::class)->sendAuto($hotel, $r, 'thank_you');
        }
    }

    private function checkedIn(Hotel $hotel, Reservation $r): void
    {
        $this->rules->fire($hotel, 'checked_in', RuleEngine::bookingFacts($r, $hotel), $r);
        $guest = $r->guest;
        // Welcome message to the guest: Wi-Fi, breakfast, menu, reception's WhatsApp.
        if ($guest && $this->a->enabled($hotel, 'guest_welcome') && $this->a->once($hotel, 'guest_welcome', "r{$r->id}")) {
            app(GuestMessenger::class)->sendAuto($hotel, $r, 'welcome');
        }
        $name = $guest?->full_name ?? '';
        $link = "/reservations/{$r->id}";

        // 6. Welcome back: a returning guest.
        if ($guest && $this->a->enabled($hotel, 'welcome_back')) {
            $past = Reservation::where('guest_id', $guest->id)->where('id', '!=', $r->id)->where('status', 'checked_out')->count();
            if ($past > 0 && $this->a->once($hotel, 'welcome_back', "r{$r->id}")) {
                $this->a->tell($hotel, 'welcome_back', $this->a->recipients($hotel, 'welcome_back', $r->branch_id), "Welcome back, {$name}", 'Stay number '.($past + 1).' with us.'.($guest->notes ? " Notes: {$guest->notes}" : ''), $link);
            }
        }
        // 7. Birthday during the stay.
        if ($guest?->date_of_birth && $this->a->enabled($hotel, 'guest_birthday')) {
            $in = HotelClock::local($r->check_in_at, $hotel)->startOfDay();
            $out = HotelClock::local($r->check_out_at, $hotel)->startOfDay();
            for ($d = $in->copy(); $d->lte($out); $d->addDay()) {
                if ($d->format('m-d') === $guest->date_of_birth->format('m-d')) {
                    if ($this->a->once($hotel, 'guest_birthday', "r{$r->id}")) {
                        $this->a->tell($hotel, 'guest_birthday', $this->a->recipients($hotel, 'guest_birthday', $r->branch_id), "{$name} has a birthday on ".$d->format('D d M'), 'A small surprise makes a big difference.', $link);
                    }
                    break;
                }
            }
        }
        // 9. Long stay (VIP): tell the manager.
        $nights = RuleEngine::bookingFacts($r, $hotel)['nights'];
        if ($r->stay_type === 'nightly' && $nights >= (int) $this->a->option($hotel, 'vip_arrival', 'nights') && $this->a->enabled($hotel, 'vip_arrival') && $this->a->once($hotel, 'vip_arrival', "r{$r->id}")) {
            $this->a->tell($hotel, 'vip_arrival', $this->a->recipients($hotel, 'vip_arrival', $r->branch_id), "Long stay arrived: {$name} ({$nights} nights)", $r->code, $link);
        }
    }

    private function cancelled(Hotel $hotel, Reservation $r): void
    {
        $this->rules->fire($hotel, 'booking_cancelled', RuleEngine::bookingFacts($r, $hotel), $r);
        $link = "/reservations/{$r->id}";

        // 3. Expired (not paid in time): tell the person who made it.
        if ($r->status === 'cancelled' && $r->cancel_reason === 'expired' && $r->created_by && $this->a->enabled($hotel, 'booking_expired') && $this->a->once($hotel, 'booking_expired', "r{$r->id}")) {
            $maker = User::where('id', $r->created_by)->where('status', 'active')->get();
            $this->a->tell($hotel, 'booking_expired', $maker, "Booking {$r->code} expired", 'No deposit arrived in time. The rooms are free again.', $link);
        }
        // 10. Money to give back: prepare the refund for a manager to approve.
        $fee = (string) ($r->cancellation_fee ?? '0');
        $refund = bcsub((string) $r->paid_total, $fee, 2);
        if (bccomp($refund, '0', 2) > 0 && $this->a->enabled($hotel, 'cancellation_refund')) {
            $approval = Approval::firstOrCreate(['fingerprint' => "refund:r{$r->id}"], [
                'branch_id' => $r->branch_id, 'kind' => 'refund', 'status' => 'pending',
                'title' => "Refund {$r->currency} {$refund} for cancelled booking {$r->code}",
                'payload' => ['reservation_id' => $r->id, 'amount' => $refund, 'currency' => $r->currency, 'fee' => Money::round($fee)],
            ]);
            if ($approval->wasRecentlyCreated) {
                $this->a->tell($hotel, 'cancellation_refund', $this->a->recipients($hotel, 'cancellation_refund', $r->branch_id), 'A refund waits for your approval', $approval->title, '/approvals');
            }
        }
    }

    // ------------------------------------------------------------------ money

    public function paymentCreated(Payment $p): void
    {
        $this->safely($p->hotel_id, function (Hotel $hotel) use ($p) {
            if ($p->type === 'refund') {
                return;
            }
            $this->rules->fire($hotel, 'payment_received', RuleEngine::paymentFacts($p, $hotel), $p->reservation_id ? Reservation::find($p->reservation_id) : null);
            // 19. Transfer without a reference number.
            $p->loadMissing('method');
            if ($p->method && $p->method->type !== 'cash' && blank($p->reference) && $p->received_by && $this->a->enabled($hotel, 'transfer_without_reference') && $this->a->once($hotel, 'transfer_without_reference', "p{$p->id}")) {
                $this->a->tell($hotel, 'transfer_without_reference', User::whereKey($p->received_by)->get(), "Add the reference number for a {$p->method->name} payment", "{$p->currency} {$p->amount}", $p->reservation_id ? "/reservations/{$p->reservation_id}" : null);
            }
        });
    }

    public function invoiceUpdated(Invoice $i): void
    {
        $this->safely($i->hotel_id, function (Hotel $hotel) use ($i) {
            // 18. Bill voided after money was paid.
            if ($i->wasChanged('status') && $i->status === 'void' && bccomp((string) $i->paid_total, '0', 2) > 0 && $this->a->enabled($hotel, 'void_after_payment') && $this->a->once($hotel, 'void_after_payment', "i{$i->id}")) {
                $this->a->tell($hotel, 'void_after_payment', $this->a->people($hotel, ['hotel_admin']), "Bill {$i->number} was voided after payment", "Paid: {$i->currency} {$i->paid_total}. Reason: {$i->void_reason}", "/billing/{$i->id}");
            }
            // 17. Large discount on a bill.
            if ($i->wasChanged('discount_total')) {
                $added = bcsub((string) $i->discount_total, (string) ($i->getOriginal('discount_total') ?? '0'), 2);
                $this->bigReduction($hotel, $i, $added, 'discount');
            }
        });
    }

    public function invoiceItemUpdated(InvoiceItem $item): void
    {
        if (! $item->wasChanged('is_waived') || ! $item->is_waived) {
            return;
        }
        $this->safely($item->hotel_id, fn (Hotel $hotel) => ($invoice = Invoice::find($item->invoice_id)) ? $this->bigReduction($hotel, $invoice, (string) $item->amount, 'waived fee') : null);
    }

    private function bigReduction(Hotel $hotel, Invoice $i, string $amount, string $what): void
    {
        $limit = (string) $this->a->option($hotel, 'large_discount', 'amount');
        if (bccomp($amount, $limit, 2) >= 0 && $this->a->enabled($hotel, 'large_discount') && $this->a->once($hotel, 'large_discount', "i{$i->id}:".md5($what.$amount.now()->format('YmdHi')))) {
            $by = auth()->user()?->name;
            $this->a->tell($hotel, 'large_discount', $this->a->recipients($hotel, 'large_discount', $i->branch_id), "Large {$what} on bill {$i->number}", "{$i->currency} ".Money::round($amount).($by ? " by {$by}" : ''), "/billing/{$i->id}");
        }
    }

    public function cashierUpdated(CashierSession $s): void
    {
        if (! $s->wasChanged('status') || $s->status !== 'closed') {
            return;
        }
        // 15. Shift closed short.
        $this->safely($s->hotel_id, function (Hotel $hotel) use ($s) {
            $short = $s->balances()->whereNotNull('difference')->where('difference', '<', 0)->get();
            if ($short->isNotEmpty() && $this->a->enabled($hotel, 'cashier_short') && $this->a->once($hotel, 'cashier_short', "c{$s->id}")) {
                $lines = $short->map(fn ($b) => "{$b->currency} ".Money::round((string) $b->difference))->implode(', ');
                $this->a->tell($hotel, 'cashier_short', $this->a->recipients($hotel, 'cashier_short', $s->branch_id), 'Cashier shift closed short: '.($s->user?->name ?? ''), $lines, '/cashier');
            }
        });
    }

    // ------------------------------------------------------------------ rooms and staff

    public function roomUpdated(Room $room): void
    {
        if (! $room->wasChanged('status')) {
            return;
        }
        $this->safely($room->hotel_id, function (Hotel $hotel) use ($room) {
            $this->rules->fire($hotel, 'room_status_changed', RuleEngine::roomFacts($room, $hotel), null, $room);
            // 21. Room ready and a guest arriving today is waiting for it.
            if ($room->status === 'available' && in_array($room->getOriginal('status'), ['dirty', 'cleaning'], true) && $this->a->enabled($hotel, 'room_ready')) {
                $start = RuleEngine::dayStart($hotel);
                $waiting = Reservation::where('branch_id', $room->branch_id)->whereIn('status', ['pending', 'confirmed'])
                    ->whereBetween('check_in_at', [$start, $start->copy()->addDay()])
                    ->whereHas('rooms', fn ($q) => $q->where('room_id', $room->id)->orWhere(fn ($w) => $w->whereNull('room_id')->where('room_type_id', $room->room_type_id)))
                    ->with('guest')->first();
                if ($waiting && $this->a->once($hotel, 'room_ready', "room{$room->id}:".now()->format('YmdH'))) {
                    $this->a->tell($hotel, 'room_ready', $this->a->recipients($hotel, 'room_ready', $room->branch_id), "Room {$room->number} is ready", 'For '.($waiting->guest?->full_name ?? $waiting->code), "/reservations/{$waiting->id}");
                }
            }
        });
    }

    public function taskCreated(HousekeepingTask $task): void
    {
        if ($task->type !== 'cleaning') {
            return;
        }
        // 20 / 24. New cleaning task after a check-out (or after an hourly stay).
        $this->safely($task->hotel_id, function (Hotel $hotel) use ($task) {
            $hourly = $task->reservation_id && Reservation::whereKey($task->reservation_id)->value('stay_type') === 'hourly';
            $key = $hourly ? 'hourly_cleaning' : 'checkout_cleaning';
            if (! $task->reservation_id || ! $this->a->enabled($hotel, $key) || ! $this->a->once($hotel, $key, "t{$task->id}")) {
                return;
            }
            $room = Room::find($task->room_id);
            $to = $task->assigned_to ? User::whereKey($task->assigned_to)->get() : $this->a->recipients($hotel, $key, $task->branch_id)->reject(fn (User $u) => $u->isHotelAdmin());
            $this->a->tell($hotel, $key, $to, 'Clean room '.($room?->number ?? ''), $hourly ? 'Hourly stay ended. The room can be sold again after cleaning.' : 'The guest checked out.', '/housekeeping');
        });
    }

    public function deviceCreated(UserDevice $device): void
    {
        // 28. Sign-in from a new device (not the person's very first device).
        $this->safely($device->hotel_id, function (Hotel $hotel) use ($device) {
            $user = User::find($device->user_id);
            if (! $user || UserDevice::where('user_id', $user->id)->count() < 2 || ! $this->a->enabled($hotel, 'new_device') || ! $this->a->once($hotel, 'new_device', "d{$device->id}")) {
                return;
            }
            $admins = $this->a->people($hotel, ['hotel_admin'])->reject(fn (User $u) => $u->id === $user->id);
            $this->a->tell($hotel, 'new_device', $admins, "{$user->name} signed in on a new device", $device->name.($device->ip ? " · {$device->ip}" : ''), '/devices');
        });
    }

    /** @param list<string> $lines */
    public function mail(string $to, Hotel $hotel, string $title, array $lines): void
    {
        if (! $hotel->maySendMessages()) {
            return; // not confirmed (or approved) yet
        }
        try {
            Mail::to($to)->send(new ReportMail($hotel->name, $title, $lines));
        } catch (Throwable $e) {
            report($e);
        }
    }
}

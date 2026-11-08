<?php

namespace App\Services\Demo;

use App\Models\Approval;
use App\Models\Branch;
use App\Models\ChargeType;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PaymentMethod;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\User;
use App\Services\HotelSettings;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * A busy demo hotel in a few seconds (decisions.md W11): a year of stays (about 70% full, busier on
 * weekends, in the holiday season and around Meskel, quieter in the rainy months), guests in house now,
 * arrivals and departures today, and bookings for the next two months.
 *
 * Rows are written in bulk (not one by one through the booking screens) so a reset stays fast, but they
 * are the same rows the screens make: bookings, rooms, nightly prices, bills with tax, payments and
 * cleaning tasks. Numbers continue from here, so new bookings get the next code.
 */
class DemoHistory
{
    private const CHUNK = 500;

    private const GUESTS = 1500;

    private Hotel $hotel;

    private string $tz;

    private Carbon $today; // hotel midnight (local)

    /** @var array<int, array{id: int, branch_id: int, room_type_id: int, number: string, type: string, price: string}> */
    private array $rooms = [];

    /** @var list<array{id: int, name: string, rate: string, inclusive: bool, applies: string}> */
    private array $taxes = [];

    /** @var list<int> */
    private array $guests = [];

    /** @var list<int> */
    private array $staff = [];

    /** @var array<string, int> method type => id */
    private array $methods = [];

    private ?int $cleaner = null; // the housekeeper who cleans the rooms

    private ?int $admin = null; // the Hotel Admin (works in Piassa)

    private int $bole = 0; // the first branch, where the rest of the team works

    private ?int $weekendRule = null;

    private ?int $breakfast = null;

    private string $deposit = '30';

    /** @var array<string, int> "reservation-2026" => last number used */
    private array $sequences = [];

    public function build(Hotel $hotel, array $branches, array $staff): void
    {
        mt_srand(2026); // the same demo every night (dates move forward)
        $this->hotel = $hotel;
        $this->tz = 'Africa/Addis_Ababa';
        $this->today = now($this->tz)->startOfDay();
        $this->bole = $branches[0]->id;
        $this->admin = User::where('hotel_id', $hotel->id)->where('email', 'demo.admin@hotelms.demo')->value('id');
        $this->staff = array_values(array_filter(array_map(fn (User $u) => $u->id, $staff), fn ($id) => $id !== $this->admin)) ?: [$this->admin];
        $this->cleaner = User::where('hotel_id', $hotel->id)->where('email', 'demo.housekeeping@hotelms.demo')->value('id');
        $this->load($branches);
        $this->makeGuests();

        $stays = [];
        foreach ($this->rooms as $room) {
            array_push($stays, ...$this->timeline($room));
        }
        $stays = array_merge($stays, $this->lostBookings());
        // Codes are given in the order bookings were made, like the real thing.
        usort($stays, fn ($a, $b) => [$a['created'], $a['in']] <=> [$b['created'], $b['in']]);

        foreach (array_chunk($stays, self::CHUNK) as $chunk) {
            $this->write($chunk);
        }
        $this->saveSequences();

        // Rooms with a guest are occupied; a few others wait for cleaning.
        $occupied = DB::table('reservation_rooms')->where('hotel_id', $hotel->id)->where('status', 'checked_in')->whereNotNull('room_id')->pluck('room_id')->all();
        Room::whereIn('id', $occupied)->update(['status' => 'occupied']);
        // A room cleaned before its next guest checked in today is clean; the others left today wait for cleaning.
        $open = DB::table('housekeeping_tasks')->where('hotel_id', $hotel->id)->where('status', '!=', 'done');
        (clone $open)->whereIn('room_id', $occupied)->update(['status' => 'done', 'started_at' => now()->subHours(2), 'completed_at' => now()->subHour(), 'completed_by' => $this->cleaner]);
        $waiting = (clone $open)->pluck('room_id')->unique()->all();
        Room::whereIn('id', $waiting)->update(['status' => 'dirty']);
        // Always a few rooms to clean, also in the morning.
        $more = Room::whereNotIn('id', $occupied)->where('status', 'available')->inRandomOrder()->limit(max(0, 4 - count($waiting)))->get();
        Room::whereIn('id', $more->pluck('id'))->update(['status' => 'dirty']);
        $this->toClean($more->all());
        app(DemoTeam::class)->build($hotel, $branches, $this->today, $this->tz);
        $this->approvals($branches);
    }

    /**
     * A few approvals waiting, so managers can try Approve / Reject in the demo (owner, QA 8 Nov). The demo runs no
     * automations, which normally make them: a refund for a cancelled paid booking and price suggestions.
     *
     * @param  list<Branch>  $branches
     */
    private function approvals(array $branches): void
    {
        $paid = DB::table('payments')->where('hotel_id', $this->hotel->id)->where('type', '!=', 'refund')->whereNotNull('reservation_id')
            ->whereIn('reservation_id', DB::table('reservations')->where('hotel_id', $this->hotel->id)->where('status', 'cancelled')->select('id'))
            ->orderByDesc('paid_at')->first();
        if ($paid) {
            $code = DB::table('reservations')->where('id', $paid->reservation_id)->value('code');
            $amount = Money::round((string) $paid->amount);
            Approval::create([
                'branch_id' => $paid->branch_id, 'kind' => 'refund', 'status' => 'pending', 'fingerprint' => "refund:r{$paid->reservation_id}",
                'title' => "Refund {$paid->currency} {$amount} for cancelled booking {$code}",
                'payload' => ['reservation_id' => $paid->reservation_id, 'amount' => $amount, 'currency' => $paid->currency, 'fee' => '0.00'],
            ]);
        }
        foreach ([[0, 5, 10, 88.0, 61.0], [1, 9, 10, 22.0, 47.0], [0, 12, 15, 91.0, 64.0]] as [$b, $days, $percent, $booked, $usual]) {
            $branch = $branches[$b] ?? $branches[0];
            $date = $this->today->copy()->addDays($days);
            $way = $booked >= 85 ? 'up' : 'down';
            $sign = $way === 'up' ? '+' : '-';
            Approval::create([
                'branch_id' => $branch->id, 'kind' => "price_{$way}", 'status' => 'pending', 'fingerprint' => "price:{$branch->id}:{$date->toDateString()}",
                'title' => "{$sign}{$percent}% on {$date->format('D d M')} at {$branch->name} (".round($booked).'% booked)',
                'payload' => ['date' => $date->toDateString(), 'percent' => $way === 'up' ? $percent : -$percent, 'occupancy' => $booked, 'usual' => $usual],
            ]);
        }
    }

    /** Rooms waiting for cleaning have a task, like after a real check-out. @param list<Room> $rooms */
    private function toClean(array $rooms): void
    {
        $now = now();
        $rows = [];
        foreach ($rooms as $i => $room) {
            $rows[] = [
                'hotel_id' => $this->hotel->id, 'branch_id' => $room->branch_id, 'room_id' => $room->id, 'reservation_id' => null,
                'assigned_to' => $i < 4 ? $this->cleaner : null, 'completed_by' => null, 'type' => 'cleaning', 'priority' => $i === 0 ? 'high' : 'normal',
                'status' => $i === 0 ? 'in_progress' : 'pending', 'started_at' => $i === 0 ? $now->copy()->subMinutes(15)->toDateTimeString() : null,
                'completed_at' => null, 'created_at' => $now->copy()->subMinutes(30 + $i * 10)->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
            ];
        }
        DB::table('housekeeping_tasks')->insert($rows);
    }

    /** The hour of day at the hotel now. */
    private function clock(): int
    {
        return (int) now($this->tz)->format('G');
    }

    // ------------------------------------------------------------------ setup

    private function load(array $branches): void
    {
        $types = RoomType::whereIn('branch_id', collect($branches)->pluck('id'))->get()->keyBy('id');
        foreach (Room::whereIn('branch_id', collect($branches)->pluck('id'))->orderBy('number')->get() as $room) {
            $type = $types[$room->room_type_id];
            $this->rooms[] = ['id' => $room->id, 'branch_id' => $room->branch_id, 'room_type_id' => $type->id, 'number' => $room->number, 'type' => $type->name, 'price' => (string) $type->nightly_price];
        }
        $this->taxes = Tax::where('is_active', true)->orderBy('id')->get()
            ->map(fn (Tax $t) => ['id' => $t->id, 'name' => $t->name, 'rate' => (string) $t->rate, 'inclusive' => (bool) $t->is_inclusive, 'applies' => $t->applies_to])->all();
        foreach (PaymentMethod::where('is_active', true)->get() as $m) {
            $this->methods[$m->type] ??= $m->id;
        }
        $this->weekendRule = RateRule::where('rule_type', 'weekend')->value('id');
        $this->breakfast = ChargeType::firstOrCreate(['name' => 'Breakfast'], ['default_price' => '350.00', 'currency' => 'ETB', 'is_quick' => true, 'sort_order' => 1])->id;
        ChargeType::firstOrCreate(['name' => 'Laundry'], ['default_price' => '200.00', 'currency' => 'ETB', 'is_quick' => true, 'sort_order' => 3]);
        ChargeType::firstOrCreate(['name' => 'Coffee / tea'], ['default_price' => '60.00', 'currency' => 'ETB', 'is_quick' => true, 'sort_order' => 2]);
        ChargeType::firstOrCreate(['name' => 'Taxi'], ['default_price' => null, 'currency' => 'ETB', 'is_quick' => true, 'sort_order' => 4]); // the amount is typed each time
        $settings = app(HotelSettings::class);
        $this->deposit = $settings->get('deposit_type') === 'percent' ? (string) $settings->get('deposit_value') : '30';
    }

    /** 1,500 guests; some come back often (a few regulars, many one or two-time guests). */
    private function makeGuests(): void
    {
        $first = ['Abebe', 'Almaz', 'Bekele', 'Chaltu', 'Dawit', 'Eden', 'Fikru', 'Genet', 'Hana', 'Kebede', 'Lidya', 'Meseret', 'Nahom', 'Rahel', 'Samuel', 'Selam', 'Tigist', 'Yared', 'Zewdu', 'Meron', 'Biruk', 'Saba', 'Yonas', 'Mahlet', 'Henok', 'Bethlehem', 'Kidus', 'Ruth', 'Natnael', 'Hiwot', 'John', 'Sarah', 'Ahmed', 'Li', 'Anna', 'David', 'Fatima', 'Omar', 'Maria', 'James'];
        $last = ['Tadesse', 'Alemu', 'Bekele', 'Girma', 'Haile', 'Kassa', 'Mengistu', 'Negash', 'Tesfaye', 'Wolde', 'Abera', 'Desta', 'Getachew', 'Lemma', 'Mulugeta', 'Shiferaw', 'Smith', 'Mohammed', 'Wang', 'Brown', 'Hassan', 'Garcia'];
        $types = DB::table('guest_types')->where('hotel_id', $this->hotel->id)->pluck('id', 'name');
        $foreignNat = ['American', 'British', 'Sudanese', 'Chinese', 'Kenyan', 'German', 'Indian', 'Somali'];
        $year = (int) $this->today->format('Y');

        $rows = [];
        $now = now()->toDateTimeString();
        for ($i = 0; $i < self::GUESTS; $i++) {
            $fi = mt_rand(0, count($first) - 1);
            $foreign = $fi >= 30 || mt_rand(1, 10) === 1;
            $idNumber = ($foreign ? 'P' : 'ET').mt_rand(1000000, 9999999);
            $note = $i % 37 === 0 ? 'Prefers a quiet room on a high floor.' : null;
            $rows[] = [
                'hotel_id' => $this->hotel->id,
                'first_name' => $first[$fi], 'last_name' => $last[$foreign ? mt_rand(16, count($last) - 1) : mt_rand(0, 15)],
                'phone' => $foreign ? '+'.mt_rand(1, 99).' '.mt_rand(100, 999).' '.mt_rand(1000000, 9999999) : '+251 9'.mt_rand(10, 99).' '.mt_rand(100000, 999999),
                'email' => mt_rand(1, 3) === 1 ? strtolower($first[$fi]).$i.'@example.com' : null,
                'nationality' => $foreign ? $foreignNat[mt_rand(0, count($foreignNat) - 1)] : 'Ethiopian',
                'id_type' => $foreign ? 'passport' : (mt_rand(1, 4) === 1 ? 'kebele_id' : 'national_id'),
                // Stored the way the guest screens store it: encrypted, plus the search hash.
                'id_number' => Crypt::encryptString($idNumber), 'id_number_hash' => Guest::idHash($idNumber),
                'gender' => mt_rand(0, 1) ? 'male' : 'female',
                'date_of_birth' => mt_rand(1, 3) === 1 ? sprintf('%d-%02d-%02d', $year - mt_rand(22, 65), mt_rand(1, 12), mt_rand(1, 28)) : null,
                'guest_type_id' => match (true) {
                    $i % 7 === 0 => $types['Corporate'] ?? null,
                    $i % 23 === 0 => $types['NGO'] ?? null,
                    $i % 31 === 0 => $types['Government'] ?? null,
                    default => null,
                },
                'notes' => $note ? Crypt::encryptString($note) : null,
                'is_blacklisted' => $i === self::GUESTS - 1, 'blacklist_reason' => $i === self::GUESTS - 1 ? 'Left without paying' : null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $part) {
            DB::table('guests')->insert($part);
        }
        $this->guests = DB::table('guests')->where('hotel_id', $this->hotel->id)->where('is_blacklisted', false)->orderBy('id')->pluck('id')->all();
    }

    // ------------------------------------------------------------------ the year, room by room

    /** How full the hotel usually is on a date (0–1). */
    private function demand(Carbon $d): float
    {
        $base = 0.64;
        $base += match ((int) $d->month) {
            12, 1 => 0.14,      // holidays, Genna, Timket
            9 => 0.10,          // Meskel, new year
            10, 11, 2, 3 => 0.04,
            7, 8 => -0.14,      // rainy season
            default => 0.0,
        };
        if (in_array($d->dayOfWeekIso, [5, 6], true)) {
            $base += 0.10;
        }
        if ($d->dayOfWeekIso === 7) {
            $base -= 0.10;
        }
        if ($d->gt($this->today)) {
            // Bookings for later dates are still coming in: fewer the further away.
            $ahead = $this->today->diffInDays($d);
            $base *= max(0.12, 1 - $ahead / 75);
        }

        return max(0.05, min(0.92, $base));
    }

    /** @return list<array<string, mixed>> */
    private function timeline(array $room): array
    {
        $out = [];
        $d = $this->today->copy()->subDays(365);
        $end = $this->today->copy()->addDays(60);
        while ($d->lt($end)) {
            // Stays last about 3 nights, so a stay starts less often than the share of full nights wanted.
            $want = $this->demand($d);
            if (mt_rand(1, 1000) / 1000 > $want / (3 - 2 * $want)) {
                $d->addDay(); // an empty night

                continue;
            }
            $nights = [1, 1, 2, 2, 2, 3, 3, 4, 5, 7][mt_rand(0, 9)];
            $in = $d->copy();
            $outDate = $in->copy()->addDays($nights);
            if ($outDate->gt($end)) {
                break;
            }
            $stay = $this->stay($room, $in, $outDate);
            $before = $out[count($out) - 1] ?? null;
            if ($stay['status'] === 'checked_in' && $before && $before['status'] === 'checked_in' && $before['out']->eq($in)) {
                $stay['status'] = 'confirmed'; // the room is still taken: this guest waits
            }
            $out[] = $stay;
            $d = $outDate->copy(); // a copy: moving $d on must not stretch this stay
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function stay(array $room, Carbon $in, Carbon $out, ?string $status = null): array
    {
        $isWalkIn = $in->lte($this->today) && mt_rand(1, 6) === 1; // walk-ins only arrive today or earlier
        $lead = $isWalkIn ? 0 : [0, 1, 2, 3, 5, 7, 10, 14, 21, 30, 45][mt_rand(0, 10)];
        $created = $in->copy()->subDays($lead)->setTime(mt_rand(8, 21), mt_rand(0, 59));
        if ($created->gt(now($this->tz))) {
            // Booked over the last weeks (not all today), during office hours.
            $created = $in->gt($this->today)
                ? $this->today->copy()->subDays(mt_rand(1, 21))->setTime(mt_rand(8, 21), mt_rand(0, 59))
                : now($this->tz)->subMinutes(mt_rand(5, 300));
        }
        $status ??= match (true) {
            $out->lt($this->today) => 'checked_out',
            // Leaving today: after check-out time most have gone.
            $out->eq($this->today) && $in->lt($this->today) => $this->clock() >= 12 && mt_rand(1, 3) <= 2 ? 'checked_out' : 'checked_in',
            $in->lt($this->today) && $out->gt($this->today) => 'checked_in',
            // Arriving today: a few came early; after check-in time most are here.
            $in->eq($this->today) => mt_rand(1, 3) <= ($this->clock() >= 14 ? 2 : 1) ? 'checked_in' : 'confirmed',
            default => mt_rand(1, 7) === 1 ? 'pending' : 'confirmed',
        };
        // A few regular guests come back often.
        $guest = mt_rand(1, 8) === 1 ? $this->guests[mt_rand(0, 24)] : $this->guests[mt_rand(0, count($this->guests) - 1)];

        return [
            'room' => $room, 'in' => $in, 'out' => $out, 'status' => $status, 'created' => $created, 'guest' => $guest,
            'source' => $isWalkIn ? 'walk_in' : ['phone', 'phone', 'email', 'agent', 'other'][mt_rand(0, 4)],
            'adults' => mt_rand(1, 10) > 3 ? 2 : 1, 'children' => mt_rand(1, 10) === 1 ? 1 : 0,
            'breakfast' => mt_rand(1, 5) === 1,
            // Bole's team works in Bole; the Hotel Admin covers Piassa.
            'by' => $room['branch_id'] === $this->bole ? $this->staff[mt_rand(0, count($this->staff) - 1)] : $this->admin,
        ];
    }

    /** About 8% of bookings were cancelled and 2% were no-shows (for the cancellation reports). */
    private function lostBookings(): array
    {
        $out = [];
        for ($i = 0; $i < 420; $i++) {
            $room = $this->rooms[mt_rand(0, count($this->rooms) - 1)];
            $in = $this->today->copy()->subDays(mt_rand(1, 360));
            $s = $this->stay($room, $in, $in->copy()->addDays(mt_rand(1, 3)), mt_rand(1, 5) === 1 ? 'no_show' : 'cancelled');
            $s['room']['id'] = null; // never got a room
            $out[] = $s;
        }

        return $out;
    }

    // ------------------------------------------------------------------ writing

    private function utc(Carbon $localDate, string $time): string
    {
        return Carbon::parse($localDate->toDateString().' '.$time, $this->tz)->utc()->toDateTimeString();
    }

    private function next(string $key): int
    {
        return $this->sequences[$key] = ($this->sequences[$key] ?? 0) + 1;
    }

    /** @param list<array<string, mixed>> $chunk */
    private function write(array $chunk): void
    {
        $h = $this->hotel->id;
        $now = now()->toDateTimeString();
        $plan = [];
        $reservations = [];

        foreach ($chunk as $i => $s) {
            $nights = [];
            $subtotal = '0.00';
            $roomTax = '0.00';
            for ($d = $s['in']->copy(); $d->lt($s['out']); $d->addDay()) {
                $weekend = $this->weekendRule && in_array($d->dayOfWeekIso, [5, 6], true);
                $amount = $weekend ? Money::round(bcmul($s['room']['price'], '1.10', 4)) : Money::round($s['room']['price']);
                $nights[] = ['date' => $d->toDateString(), 'amount' => $amount, 'weekend' => $weekend];
                $subtotal = Money::add($subtotal, $amount);
                $roomTax = Money::add($roomTax, $this->exclusive($this->lineTaxes($amount, 'room'))); // per line, like the bill
            }
            $extras = $s['breakfast'] ? Money::mul('350.00', (string) (count($nights) * $s['adults'])) : '0.00';
            $extraTax = $this->exclusive($this->lineTaxes($extras, 'extra'));
            $total = Money::add($subtotal, $roomTax);
            $billTotal = Money::add(Money::add($total, $extras), $extraTax);
            $deposit = Money::percentOf($total, $this->deposit);

            $paid = match ($s['status']) {
                'checked_out' => $billTotal,
                'checked_in' => mt_rand(1, 3) === 1 ? $billTotal : $deposit,
                'confirmed' => $deposit,
                default => '0.00',
            };
            $year = $s['created']->format('Y');
            $code = sprintf('RES-%s-%05d', substr($year, 2), $this->next("reservation-{$year}"));
            $inAt = $this->utc($s['in'], '14:00:00');
            $outAt = $this->utc($s['out'], '12:00:00');
            $lost = in_array($s['status'], ['cancelled', 'no_show'], true);
            $plan[$code] = $s + compact('nights', 'subtotal', 'extras', 'roomTax', 'extraTax', 'total', 'billTotal', 'deposit', 'paid', 'inAt', 'outAt');

            $reservations[] = [
                'hotel_id' => $h, 'branch_id' => $s['room']['branch_id'], 'code' => $code, 'guest_id' => $s['guest'], 'source' => $s['source'], 'stay_type' => 'nightly',
                'check_in_at' => $inAt, 'check_out_at' => $outAt, 'adults' => $s['adults'], 'children' => $s['children'], 'status' => $s['status'],
                'expires_at' => $s['status'] === 'pending' ? now()->addHours(mt_rand(4, 24))->toDateTimeString() : null,
                'currency' => 'ETB', 'subtotal' => $subtotal, 'discount_percent' => '0.00', 'discount_amount' => '0.00',
                'tax_total' => $roomTax, 'total' => $total, 'deposit_required' => $deposit,
                // Booking money: the stay itself (extras are on the bill).
                'paid_total' => in_array($s['status'], ['checked_out', 'checked_in'], true) ? $paid : ($s['status'] === 'confirmed' ? $deposit : '0.00'),
                'cancellation_fee' => $lost ? '0.00' : null,
                'created_by' => $s['by'],
                'cancelled_at' => $lost ? Carbon::parse($inAt)->subHours(mt_rand(2, 72))->toDateTimeString() : null,
                'cancel_reason' => $s['status'] === 'cancelled' ? ['Plans changed', 'Found another hotel', 'Trip cancelled', 'Booked twice'][mt_rand(0, 3)] : ($s['status'] === 'no_show' ? 'Did not arrive' : null),
                'cancelled_by' => $lost ? $s['by'] : null,
                'created_at' => $s['created']->copy()->utc()->toDateTimeString(), 'updated_at' => $now,
            ];
        }
        DB::table('reservations')->insert($reservations);
        $ids = DB::table('reservations')->where('hotel_id', $h)->whereIn('code', array_keys($plan))->pluck('id', 'code');

        // Rooms of each booking
        $rows = [];
        foreach ($plan as $code => $p) {
            $checkedIn = in_array($p['status'], ['checked_in', 'checked_out'], true);
            // Most bookings get their room when booked; some wait for one (the "No room yet" row).
            $assigned = $checkedIn || ($p['status'] === 'confirmed' && mt_rand(1, 10) <= 7) || ($p['status'] === 'pending' && mt_rand(1, 10) <= 4);
            $rows[] = [
                'hotel_id' => $h, 'reservation_id' => $ids[$code], 'room_type_id' => $p['room']['room_type_id'], 'room_id' => $assigned ? $p['room']['id'] : null,
                'check_in_at' => $p['inAt'], 'check_out_at' => $p['outAt'], 'blocked_until' => $p['outAt'],
                'actual_check_in_at' => $checkedIn ? Carbon::parse($p['inAt'])->addMinutes(mt_rand(-60, 240))->min(now())->toDateTimeString() : null,
                'actual_check_out_at' => $p['status'] === 'checked_out' ? Carbon::parse($p['outAt'])->subMinutes(mt_rand(10, 150))->toDateTimeString() : null,
                'adults' => $p['adults'], 'children' => $p['children'], 'total_amount' => $p['subtotal'], 'status' => $p['status'],
                'created_at' => $p['created']->copy()->utc()->toDateTimeString(), 'updated_at' => $now,
            ];
        }
        DB::table('reservation_rooms')->insert($rows);
        $rr = DB::table('reservation_rooms')->whereIn('reservation_id', $ids->values())->pluck('id', 'reservation_id');

        $rates = [];
        foreach ($plan as $code => $p) {
            foreach ($p['nights'] as $n) {
                $rates[] = [
                    'reservation_room_id' => $rr[$ids[$code]], 'date' => $n['date'], 'label' => 'Night', 'amount' => $n['amount'],
                    'rate_rule_id' => $n['weekend'] ? $this->weekendRule : null, 'rule_name' => $n['weekend'] ? 'Weekend' : null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rates, 1000) as $part) {
            DB::table('reservation_room_rates')->insert($part);
        }

        $this->bills($plan, $ids, $rr, $now);
    }

    /** Bills (from check-in), their lines with tax, payments and cleaning after check-out. */
    private function bills(array $plan, $ids, $rr, string $now): void
    {
        $h = $this->hotel->id;
        $invoices = [];
        $numbers = [];
        foreach ($plan as $code => $p) {
            if (! in_array($p['status'], ['checked_in', 'checked_out'], true)) {
                continue;
            }
            $year = Carbon::parse($p['inAt'])->tz($this->tz)->format('Y');
            $number = sprintf('INV-%s-%05d', $year, $this->next("invoice-{$year}"));
            $numbers[$number] = $code;
            $tax = Money::add($p['roomTax'], $p['extraTax']);
            $sub = Money::add($p['subtotal'], $p['extras']);
            $balance = Money::sub($p['billTotal'], $p['paid']);
            $invoices[] = [
                'hotel_id' => $h, 'branch_id' => $p['room']['branch_id'], 'reservation_id' => $ids[$code], 'guest_id' => $p['guest'], 'number' => $number, 'currency' => 'ETB',
                'subtotal' => $sub, 'discount_total' => '0.00', 'tax_total' => $tax, 'total' => $p['billTotal'], 'paid_total' => $p['paid'], 'balance' => $balance,
                'status' => Money::isPositive($balance) ? 'partially_paid' : 'paid', 'issued_at' => $p['inAt'], 'created_at' => $p['inAt'], 'updated_at' => $now,
            ];
        }
        if (! $invoices) {
            $this->payments($plan, $ids, collect(), $now);

            return;
        }
        DB::table('invoices')->insert($invoices);
        $invoiceIds = DB::table('invoices')->where('hotel_id', $h)->whereIn('number', array_keys($numbers))->pluck('id', 'number');
        $byCode = [];
        foreach ($numbers as $number => $code) {
            $byCode[$code] = $invoiceIds[$number];
        }

        $items = [];
        foreach ($byCode as $code => $invoiceId) {
            $p = $plan[$code];
            foreach ($p['nights'] as $n) {
                $items[] = [
                    'hotel_id' => $h, 'invoice_id' => $invoiceId, 'type' => 'room', 'description' => "Room {$p['room']['number']} · {$p['room']['type']} · Night",
                    'quantity' => '1.00', 'unit_price' => $n['amount'], 'amount' => $n['amount'], 'reservation_room_id' => $rr[$ids[$code]], 'charge_type_id' => null,
                    'service_date' => $n['date'], 'created_by' => $p['by'], 'created_at' => $p['inAt'], 'updated_at' => $now,
                ];
            }
            if (Money::isPositive($p['extras'])) {
                $qty = count($p['nights']) * $p['adults'];
                $items[] = [
                    'hotel_id' => $h, 'invoice_id' => $invoiceId, 'type' => 'extra', 'description' => 'Breakfast',
                    'quantity' => $qty.'.00', 'unit_price' => '350.00', 'amount' => $p['extras'], 'reservation_room_id' => $rr[$ids[$code]], 'charge_type_id' => $this->breakfast,
                    'service_date' => $p['in']->toDateString(), 'created_by' => $p['by'], 'created_at' => $p['inAt'], 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($items, 1000) as $part) {
            DB::table('invoice_items')->insert($part);
        }
        if ($this->taxes) {
            DB::table('invoice_items')->whereIn('invoice_id', array_values($byCode))->orderBy('id')
                ->chunk(1000, function ($rows) use ($now) {
                    $taxes = [];
                    foreach ($rows as $item) {
                        foreach ($this->lineTaxes((string) $item->amount, $item->type) as $t) {
                            $taxes[] = ['invoice_item_id' => $item->id] + $t + ['created_at' => $now, 'updated_at' => $now];
                        }
                    }
                    if ($taxes) {
                        DB::table('invoice_item_taxes')->insert($taxes);
                    }
                });
        }

        $this->payments($plan, $ids, collect($byCode), $now);
    }

    private function payments(array $plan, $ids, $invoiceByCode, string $now): void
    {
        $h = $this->hotel->id;
        $payments = [];
        $tasks = [];
        $methods = array_values(array_filter([
            $this->methods['cash'] ?? null, $this->methods['cash'] ?? null, $this->methods['mobile_money'] ?? null,
            $this->methods['mobile_money'] ?? null, $this->methods['bank_transfer'] ?? null, $this->methods['card'] ?? null,
        ])) ?: array_values($this->methods);
        foreach ($plan as $code => $p) {
            $base = [
                'hotel_id' => $h, 'branch_id' => $p['room']['branch_id'], 'reservation_id' => $ids[$code], 'invoice_id' => $invoiceByCode[$code] ?? null,
                'cashier_session_id' => null, 'currency' => 'ETB', 'exchange_rate' => '1.000000', 'refund_reason' => null,
                'received_by' => $p['by'], 'updated_at' => $now,
            ];
            $ref = fn () => mt_rand(1, 5) === 1 ? null : 'TX'.mt_rand(10000000, 99999999);
            if (in_array($p['status'], ['confirmed', 'checked_in', 'checked_out'], true) && Money::isPositive($p['deposit']) && $p['source'] !== 'walk_in') {
                $at = $p['created']->copy()->addMinutes(mt_rand(5, 180))->min(now($this->tz))->utc()->toDateTimeString();
                $payments[] = $base + ['payment_method_id' => $methods[mt_rand(2, count($methods) - 1)] ?? $methods[0], 'type' => 'deposit', 'amount' => $p['deposit'], 'amount_in_base' => $p['deposit'], 'reference' => $ref(), 'paid_at' => $at, 'created_at' => $at];
                $rest = Money::sub($p['paid'], $p['deposit']);
            } else {
                $rest = $p['paid'];
            }
            if (in_array($p['status'], ['checked_in', 'checked_out'], true) && Money::isPositive($rest)) {
                $at = ($p['status'] === 'checked_out' ? Carbon::parse($p['outAt'])->subMinutes(mt_rand(20, 180)) : Carbon::parse($p['inAt'])->addMinutes(mt_rand(10, 90))->min(now()))->toDateTimeString();
                $method = $methods[mt_rand(0, count($methods) - 1)];
                $payments[] = $base + ['payment_method_id' => $method, 'type' => 'payment', 'amount' => $rest, 'amount_in_base' => $rest, 'reference' => $method === ($this->methods['cash'] ?? 0) ? null : $ref(), 'paid_at' => $at, 'created_at' => $at];
            }
            // Cleaning after each check-out in the last 30 days (the housekeeping history).
            if ($p['status'] === 'checked_out' && $p['room']['id'] && $p['out']->gte($this->today->copy()->subDays(30))) {
                $start = Carbon::parse($p['outAt'])->addMinutes(mt_rand(10, 90));
                $end = $start->copy()->addMinutes(mt_rand(20, 50));
                $done = $end->lt(now()); // a room left today may still be waiting
                $tasks[] = [
                    'hotel_id' => $h, 'branch_id' => $p['room']['branch_id'], 'room_id' => $p['room']['id'], 'reservation_id' => $ids[$code], 'assigned_to' => $this->cleaner, 'completed_by' => $done ? $this->cleaner : null,
                    'type' => 'cleaning', 'priority' => 'normal', 'status' => $done ? 'done' : 'pending', 'started_at' => $done ? $start->toDateTimeString() : null,
                    'completed_at' => $done ? $end->toDateTimeString() : null, 'created_at' => $p['outAt'], 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($payments, 1000) as $part) {
            DB::table('payments')->insert($part);
        }
        foreach (array_chunk($tasks, 1000) as $part) {
            DB::table('housekeeping_tasks')->insert($part);
        }
    }

    // ------------------------------------------------------------------ tax and numbers

    /** @return list<array{tax_id: int, name: string, rate: string, is_inclusive: bool, amount: string}> */
    private function lineTaxes(string $amount, string $type): array
    {
        if (! Money::isPositive($amount)) {
            return [];
        }
        $group = in_array($type, ['room', 'early_check_in', 'late_check_out', 'early_check_out', 'discount'], true) ? 'rooms' : 'extras';
        $out = [];
        foreach ($this->taxes as $t) {
            if ($t['applies'] !== 'all' && $t['applies'] !== $group) {
                continue;
            }
            $tax = $t['inclusive'] ? Money::round(bcdiv(bcmul($amount, $t['rate'], 6), bcadd('100', $t['rate'], 6), 6)) : Money::percentOf($amount, $t['rate']);
            $out[] = ['tax_id' => $t['id'], 'name' => $t['name'], 'rate' => $t['rate'], 'is_inclusive' => $t['inclusive'], 'amount' => $tax];
        }

        return $out;
    }

    /** @param list<array> $taxes */
    private function exclusive(array $taxes): string
    {
        return Money::sum(array_map(fn ($t) => $t['amount'], array_filter($taxes, fn ($t) => ! $t['is_inclusive'])));
    }

    private function saveSequences(): void
    {
        foreach ($this->sequences as $key => $last) {
            DB::table('number_sequences')->updateOrInsert(['hotel_id' => $this->hotel->id, 'key' => $key], ['last' => $last, 'updated_at' => now(), 'created_at' => now()]);
        }
    }
}

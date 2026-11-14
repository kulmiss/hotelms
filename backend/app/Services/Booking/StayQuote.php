<?php

namespace App\Services\Booking;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Tax;
use App\Services\HotelSettings;
use App\Services\PriceCalculator;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Times, prices, taxes and deposit for a stay (workflows.md W1, W8).
 * - nightly: dates; check-in/out at the branch's times
 * - day_use: a start and end time on the same day, at the room type's day-use price
 * - hourly:  a start time and a number of hours (at least the hotel's minimum)
 * Day-use and hourly stays also hold the room for the cleaning time afterwards.
 */
class StayQuote
{
    public function __construct(private PriceCalculator $prices, private HotelSettings $settings) {}

    /** @return array{in: Carbon, out: Carbon, until: Carbon} (UTC) */
    public function times(Hotel $hotel, Branch $branch, string $stayType, string $checkIn, ?string $checkOut, ?int $hours): array
    {
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);
        $zone = HotelClock::zone($hotel);

        if ($stayType === 'nightly') {
            $inDate = Carbon::parse(substr($checkIn, 0, 10), $zone);
            $outDate = Carbon::parse(substr((string) $checkOut, 0, 10), $zone);
            if (! $checkOut || $outDate->lte($inDate)) {
                throw ValidationException::withMessages(['check_out' => 'Check-out must be after check-in.']);
            }
            if ($inDate->diffInDays($outDate) > 90) {
                throw ValidationException::withMessages(['check_out' => 'A booking can be at most 90 nights.']);
            }
            $in = $inDate->copy()->setTimeFromTimeString((string) ($branch->check_in_time ?: '14:00'))->utc();
            $out = $outDate->copy()->setTimeFromTimeString((string) ($branch->check_out_time ?: '12:00'))->utc();

            return ['in' => $in, 'out' => $out, 'until' => $out->copy()];
        }

        $in = HotelClock::parse($checkIn, $hotel);
        $cleaning = (int) $rules['hourly_cleaning_minutes'];

        if ($stayType === 'hourly') {
            $min = max(1, (int) $rules['hourly_min_hours']);
            if (! $hours || $hours < $min) {
                throw ValidationException::withMessages(['hours' => "At least {$min} hours."]);
            }
            if ($hours > 24) {
                throw ValidationException::withMessages(['hours' => 'At most 24 hours. Book nights instead.']);
            }
            $out = $in->copy()->addHours($hours);
        } else { // day_use
            $out = $checkOut ? HotelClock::parse($checkOut, $hotel) : null;
            if (! $out || $out->lte($in)) {
                throw ValidationException::withMessages(['check_out' => 'The end time must be after the start time.']);
            }
            if (HotelClock::local($in, $hotel)->toDateString() !== HotelClock::local($out, $hotel)->toDateString()) {
                throw ValidationException::withMessages(['check_out' => 'A day-use stay starts and ends on the same day.']);
            }
        }

        return ['in' => $in, 'out' => $out, 'until' => $out->copy()->addMinutes($cleaning)];
    }

    /**
     * Price of one room for the stay.
     *
     * @return array{total: string, lines: list<array{date: string, label: string, amount: string, rate_rule_id: ?int, rule_name: ?string}>}
     */
    public function price(Hotel $hotel, RoomType $type, string $stayType, Carbon $in, Carbon $out, ?int $guestTypeId, ?Carbon $bookedAt = null): array
    {
        $localIn = HotelClock::local($in, $hotel);

        if ($stayType === 'nightly') {
            // Early-bird / last-minute count from the day the booking was made (hotel time).
            $bookedOn = HotelClock::local($bookedAt ?? now(), $hotel)->startOfDay();
            $stay = $this->prices->stay($type, $localIn->copy()->startOfDay(), HotelClock::local($out, $hotel)->startOfDay(), $guestTypeId, $bookedOn);
            $lines = array_map(fn ($n) => [
                'date' => $n['date'], 'label' => 'Night', 'amount' => $n['amount'],
                'rate_rule_id' => $n['rule']['id'] ?? null, 'rule_name' => $n['rule']['name'] ?? null,
            ], $stay['nights']);

            return ['total' => $stay['total'], 'lines' => $lines];
        }

        if ($stayType === 'day_use') {
            if ($type->day_use_price === null) {
                throw ValidationException::withMessages(['rooms' => "{$type->name} is not offered for day use."]);
            }
            $amount = (string) $type->day_use_price;

            return ['total' => $amount, 'lines' => [['date' => $localIn->toDateString(), 'label' => 'Day use', 'amount' => $amount, 'rate_rule_id' => null, 'rule_name' => null]]];
        }

        if ($type->hourly_price === null) {
            throw ValidationException::withMessages(['rooms' => "{$type->name} is not offered by the hour."]);
        }
        $hours = (int) ceil($in->diffInMinutes($out) / 60);
        $amount = Money::mul((string) $type->hourly_price, (string) $hours);

        return ['total' => $amount, 'lines' => [['date' => $localIn->toDateString(), 'label' => "{$hours} hours", 'amount' => $amount, 'rate_rule_id' => null, 'rule_name' => null]]];
    }

    /**
     * A stay shorter than the minimum of a season or holiday it touches (not on the Free plan).
     *
     * @return array{name: string, nights: int}|null
     */
    public function minStayProblem(Hotel $hotel, RoomType $type, string $stayType, Carbon $in, Carbon $out): ?array
    {
        if ($stayType !== 'nightly') {
            return null;
        }

        return $this->prices->minStayProblem($type, HotelClock::local($in, $hotel)->startOfDay(), HotelClock::local($out, $hotel)->startOfDay());
    }

    /**
     * The same nights at a price set by hand (permission reservations.override_price).
     *
     * @param  list<array{date: string, label: string, amount: string, rate_rule_id: ?int, rule_name: ?string}>  $lines
     * @return array{total: string, lines: list<array{date: string, label: string, amount: string, rate_rule_id: ?int, rule_name: ?string}>}
     */
    public function overridden(array $lines, string $perNight): array
    {
        $amount = Money::round($perNight);
        $lines = array_map(fn ($l) => ['amount' => $amount, 'rate_rule_id' => null, 'rule_name' => 'Price set by hand'] + $l, $lines);

        return ['total' => Money::sum(array_column($lines, 'amount')), 'lines' => $lines];
    }

    /** Taxes added on top of a room amount (taxes "included in the price" add nothing). */
    public function addedTaxes(string $net): string
    {
        return Money::sum(Tax::where('is_active', true)->where('is_inclusive', false)->whereIn('applies_to', ['all', 'rooms'])
            ->get()->map(fn (Tax $t) => Money::percentOf($net, (string) $t->rate)));
    }

    /**
     * Deposit needed to confirm (workflows.md Q1). 0 when the hotel does not ask for one.
     *
     * @param  list<string>  $firstNights  first night (or block) of each room
     */
    public function deposit(string $total, array $firstNights): string
    {
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);
        if (! $rules['deposit_required']) {
            return '0.00';
        }
        $value = (string) $rules['deposit_value'];

        $amount = match ($rules['deposit_type']) {
            'fixed' => Money::round($value),
            'first_night' => Money::sum($firstNights),
            default => Money::percentOf($total, $value),
        };

        return Money::min($amount, $total);
    }
}

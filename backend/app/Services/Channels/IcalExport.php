<?php

namespace App\Services\Channels;

use App\Models\Hotel;
use App\Models\IcalBlock;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Support\HotelClock;
use Illuminate\Support\Str;

/**
 * A room's busy dates as an iCal calendar for Airbnb, Booking.com… (the room's secret link).
 * Only dates: no guest names, phones or prices. Our bookings, and busy dates of the room's other imported links
 * (so a booking from one site also blocks the others). A day-use or hourly stay blocks its day.
 */
class IcalExport
{
    public static function newSecret(Room $room): string
    {
        $secret = Str::random(40);
        $room->forceFill(['ical_token' => hash('sha256', $secret), 'ical_secret' => $secret])->save();

        return $secret;
    }

    public static function url(Room $room): ?string
    {
        return $room->ical_secret ? rtrim((string) config('app.url'), '/').'/ical/'.$room->ical_secret.'.ics' : null;
    }

    public function calendar(Hotel $hotel, Room $room): string
    {
        $today = HotelClock::now($hotel)->startOfDay();
        $from = $today->copy()->subDays(30)->utc();
        $until = $today->copy()->addYears(2)->utc();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'hotelms';
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//HotelMS//Calendar sync//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->text($hotel->name.' – '.$room->number)];

        $stays = ReservationRoom::whereIn('status', Reservation::ACTIVE)->where('room_id', $room->id)
            ->where('check_in_at', '<', $until)->where('blocked_until', '>', $from)->get(['id', 'check_in_at', 'check_out_at']);
        foreach ($stays as $s) {
            $start = HotelClock::local($s->check_in_at, $hotel)->startOfDay();
            $end = HotelClock::local($s->check_out_at, $hotel)->startOfDay();
            if ($end->lte($start)) {
                $end = $start->copy()->addDay(); // a day-use or hourly stay: that day
            }
            $lines = [...$lines, ...$this->event("stay-{$s->id}@{$host}", $start->format('Ymd'), $end->format('Ymd'), 'Booked', $stamp)];
        }
        $blocks = IcalBlock::where('room_id', $room->id)->where('ends_at', '>', $from)->where('starts_at', '<', $until)
            ->whereHas('feed', fn ($q) => $q->where('is_active', true))->get(['id', 'starts_on', 'ends_on']);
        foreach ($blocks as $b) {
            $lines = [...$lines, ...$this->event("block-{$b->id}@{$host}", $b->starts_on->format('Ymd'), $b->ends_on->format('Ymd'), 'Not available', $stamp)];
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    /** @return list<string> */
    private function event(string $uid, string $start, string $end, string $summary, string $stamp): array
    {
        return ['BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:'.$stamp, 'DTSTART;VALUE=DATE:'.$start, 'DTEND;VALUE=DATE:'.$end, 'SUMMARY:'.$summary, 'TRANSP:OPAQUE', 'END:VEVENT'];
    }

    private function text(string $v): string
    {
        return str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\;', '\,', ' ', ''], $v);
    }
}

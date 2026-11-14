<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Room;
use App\Services\Channels\CalendarSync;
use App\Services\Channels\IcalExport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A room's secret calendar link /ical/{secret}.ics, read by Airbnb, Booking.com… (no sign-in, no cookies).
 * Unknown, reset or switched-off links answer 404. At most 60 reads an hour per link.
 */
class IcalExportController extends Controller
{
    public function show(string $secret, TenantContext $tenant, IcalExport $export, CalendarSync $limits): Response
    {
        $hash = hash('sha256', $secret);
        $room = $tenant->acrossAllHotels(fn () => Room::where('ical_token', $hash)->first());
        $hotel = $room ? Hotel::find($room->hotel_id) : null;
        abort_unless($room && $hotel && in_array($hotel->status, ['trial', 'active', 'grace'], true) && $limits->limit($hotel) !== 0, 404);
        abort_if(RateLimiter::tooManyAttempts('ical-export:'.$room->id, 60), 429);
        RateLimiter::hit('ical-export:'.$room->id, 3600);

        return response($tenant->runAs($hotel, fn () => $export->calendar($hotel, $room)), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="room-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $room->number).'.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}

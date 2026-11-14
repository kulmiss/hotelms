<?php

namespace App\Services\Channels;

use App\Models\Hotel;
use App\Models\IcalBlock;
use App\Models\IcalFeed;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Services\Automation\Automations;
use App\Services\Booking\Availability;
use App\Support\HotelClock;
use App\Support\SafeHost;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Reads one imported calendar link (owner, 14 Nov 2026): every 15 minutes from the scheduler, or "Sync now".
 * The busy dates replace the feed's old ones; a link that fails keeps the old dates (and shows the error).
 * A clash with a booking of ours is only a warning: nothing is cancelled (owner's choice).
 */
class IcalSync
{
    public const EVERY_MINUTES = 15;

    public const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private Availability $availability) {}

    /** @return array{ok: bool, events: int, conflicts: int, error: ?string} */
    public function sync(IcalFeed $feed): array
    {
        $hotel = Hotel::findOrFail($feed->hotel_id);
        $zone = HotelClock::zone($hotel);
        try {
            $events = IcalParser::events($this->download((string) $feed->url), $zone);
        } catch (Throwable $e) {
            $message = $e instanceof RuntimeException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The link could not be read. Check it and try again.';
            $feed->update(['last_synced_at' => now(), 'last_status' => 'error', 'last_error' => mb_substr($message, 0, 200)]);

            return ['ok' => false, 'events' => $feed->events_count, 'conflicts' => count($feed->conflicts ?? []), 'error' => $message];
        }

        $today = HotelClock::now($hotel)->startOfDay();
        $events = array_values(array_filter($events, fn ($e) => $e['ends_on'] >= $today->copy()->subDay()->toDateString() && $e['starts_on'] <= $today->copy()->addYears(2)->toDateString()));
        $branch = $feed->branch;
        $typeId = $feed->room_type_id ?? Room::whereKey($feed->room_id)->value('room_type_id');
        $in = (string) ($branch?->check_in_time ?: '14:00');
        $out = (string) ($branch?->check_out_time ?: '12:00');

        DB::transaction(function () use ($feed, $events, $zone, $typeId, $in, $out) {
            $keep = [];
            foreach ($events as $e) {
                $block = IcalBlock::updateOrCreate(['ical_feed_id' => $feed->id, 'uid' => $e['uid']], [
                    'room_id' => $feed->room_id, 'room_type_id' => $typeId,
                    'starts_on' => $e['starts_on'], 'ends_on' => $e['ends_on'], 'summary' => $e['summary'],
                    'starts_at' => Carbon::parse($e['starts_on'].' '.$in, $zone)->utc(),
                    'ends_at' => Carbon::parse($e['ends_on'].' '.$out, $zone)->utc(),
                ]);
                $keep[] = $block->id;
            }
            IcalBlock::where('ical_feed_id', $feed->id)->whereNotIn('id', $keep ?: [0])->delete();
        });

        $conflicts = $this->conflicts($feed->fresh());
        $before = collect($feed->conflicts ?? [])->pluck('key')->all();
        $feed->update(['last_synced_at' => now(), 'last_status' => 'ok', 'last_error' => null, 'events_count' => count($events), 'conflicts' => $conflicts]);
        $new = array_values(array_filter($conflicts, fn ($c) => ! in_array($c['key'], $before, true)));
        if ($new) {
            $this->tell($hotel, $feed, $new);
        }

        return ['ok' => true, 'events' => count($events), 'conflicts' => count($conflicts), 'error' => null];
    }

    /**
     * Busy dates that clash with our bookings: the same room booked, or more rooms of the type held than exist.
     *
     * @return list<array{key: string, starts_on: string, ends_on: string, code: ?string, text: string}>
     */
    public function conflicts(IcalFeed $feed): array
    {
        $out = [];
        foreach ($feed->blocks()->orderBy('starts_on')->get() as $b) {
            if ($feed->room_id) {
                $clash = ReservationRoom::with('reservation:id,code')->whereIn('status', Reservation::ACTIVE)->where('room_id', $feed->room_id)
                    ->where('check_in_at', '<', $b->ends_at)->where('blocked_until', '>', $b->starts_at)->first();
                if ($clash) {
                    $out[] = ['key' => "{$b->uid}|{$clash->reservation_id}", 'starts_on' => $b->starts_on->toDateString(), 'ends_on' => $b->ends_on->toDateString(), 'code' => $clash->reservation?->code, 'text' => "Booking {$clash->reservation?->code} is in the same room"];
                }
            } elseif ($feed->branch_id) {
                $row = $this->availability->forBranch($feed->branch_id, $b->starts_at, $b->ends_at)->first(fn ($r) => $r['room_type']->id === $feed->room_type_id);
                if ($row && $row['booked'] > $row['total']) {
                    $out[] = ['key' => "{$b->uid}|type", 'starts_on' => $b->starts_on->toDateString(), 'ends_on' => $b->ends_on->toDateString(), 'code' => null, 'text' => "More {$row['room_type']->name} rooms are taken than you have"];
                }
            }
        }

        return $out;
    }

    /** @param list<array{starts_on: string, ends_on: string, text: string}> $conflicts */
    private function tell(Hotel $hotel, IcalFeed $feed, array $conflicts): void
    {
        $staff = User::where('hotel_id', $hotel->id)->where('status', 'active')->get()->filter(fn (User $u) => $u->hasPermission('reservations.edit'));
        $first = $conflicts[0];
        app(Automations::class)->tell($hotel, 'ical_conflict', $staff, "Calendar clash: {$feed->name}",
            "{$first['starts_on']} – {$first['ends_on']}: {$first['text']}".(count($conflicts) > 1 ? ' (+'.(count($conflicts) - 1).' more)' : '').'. Nothing was cancelled.', '/settings/calendar-sync');
    }

    /** Only links on the internet (never the server's own network), at most 2 MB, 3 redirects, 15 seconds. */
    private function download(string $url): string
    {
        $this->check(new Uri($url));
        $response = Http::timeout(15)->connectTimeout(5)
            ->withHeaders(['User-Agent' => 'HotelMS calendar sync', 'Accept' => 'text/calendar, */*'])
            ->withOptions([
                'allow_redirects' => ['max' => 3, 'protocols' => ['https', 'http'], 'on_redirect' => fn (RequestInterface $r, $res, UriInterface $to) => $this->check($to)],
                'on_headers' => function ($res) {
                    if ((int) $res->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                        throw new RuntimeException('The calendar is too big.');
                    }
                },
            ])
            ->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("The link answered with an error ({$response->status()}). Check it in the other site and copy it again.");
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('The calendar is too big.');
        }

        return $body;
    }

    private function check(UriInterface $uri): void
    {
        if (! in_array(strtolower($uri->getScheme()), ['https', 'http'], true) || ! SafeHost::isPublic($uri->getHost())) {
            throw new RuntimeException('Use the calendar link from the other site (an internet address starting with https://).');
        }
    }
}

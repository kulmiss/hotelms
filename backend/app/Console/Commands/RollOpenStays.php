<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\Automation\Automations;
use App\Services\Booking\FrontDesk;
use App\Support\HotelClock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Open stays (workflows.md Q16), every five minutes from cron: guests still in after the check-out time get
 * one more night on their bill. Reception is told when the room is booked for the next night (a day ahead,
 * and again if the night could not be added) and every N nights (the hotel sets N, default 14).
 */
class RollOpenStays extends Command
{
    protected $signature = 'stays:roll-open';

    protected $description = 'Add the next night to open stays whose guest is still in';

    public function handle(TenantContext $tenant, Automations $automations): int
    {
        $added = 0;
        Hotel::query()->whereIn('id', fn ($q) => $q->select('hotel_id')->from('reservations')->where('status', 'checked_in')->where('open_stay', true))
            ->each(function (Hotel $hotel) use ($tenant, $automations, &$added) {
                try {
                    $added += $tenant->runAs($hotel, fn () => $this->forHotel($hotel, $automations));
                } catch (Throwable $e) {
                    report($e); // one hotel's problem never stops the others
                }
            });
        $this->info("Added {$added} night(s).");

        return self::SUCCESS;
    }

    private function forHotel(Hotel $hotel, Automations $automations): int
    {
        $desk = app(FrontDesk::class);
        $added = 0;
        Reservation::with(['guest:id,first_name,last_name', 'rooms.room:id,number'])->where('status', 'checked_in')->where('open_stay', true)->get()
            ->each(function (Reservation $r) use ($hotel, $desk, $automations, &$added) {
                // The same lock as a person working on this booking (OneAtATime).
                $lock = Cache::lock("one-at-a-time:reservation:{$r->hotel_id}:{$r->id}", 30);
                if (! $lock->get()) {
                    return; // someone is checking the guest out right now: try again later
                }
                try {
                    $rooms = $r->rooms->map(fn ($rr) => $rr->room?->number)->filter()->implode(', ');
                    $who = trim(($r->guest?->full_name ?? '').' · Room '.$rooms);
                    foreach ($desk->rollOpenStay($r) as $step) {
                        if ($step['ok']) {
                            $added++;
                            $r = $step['reservation'];
                        } elseif ($automations->enabled($hotel, 'open_stay_room_booked') && $automations->once($hotel, 'open_stay_room_booked', "stuck-r{$r->id}-".$r->check_out_at->toDateString())) {
                            $automations->tell($hotel, 'open_stay_room_booked', $automations->recipients($hotel, 'open_stay_room_booked', $r->branch_id),
                                "Open stay: no night added for {$r->code}", "{$who} · {$step['problem']} Move the guest or check them out.", "/reservations/{$r->id}", true);
                        }
                    }
                    $r = $r->fresh();
                    if ($r->status !== 'checked_in') {
                        return;
                    }
                    // A day ahead: the room is booked for the next night.
                    if ($automations->enabled($hotel, 'open_stay_room_booked') && ! $desk->freeOneMoreNight($r)
                        && $automations->once($hotel, 'open_stay_room_booked', "next-r{$r->id}-".$r->check_out_at->toDateString())) {
                        $by = HotelClock::local($r->check_out_at, $hotel)->format('j M H:i');
                        $automations->tell($hotel, 'open_stay_room_booked', $automations->recipients($hotel, 'open_stay_room_booked', $r->branch_id),
                            "Open stay: room booked after {$by}", "{$who} · another guest has this room next. Ask the guest, or move them before {$by}.", "/reservations/{$r->id}");
                    }
                    // Every N nights: ask the guest how long they stay (and take a payment).
                    $nights = $r->rooms->first()?->rates()->where('label', 'Night')->count() ?? 0;
                    $every = max(1, (int) $automations->option($hotel, 'open_stay_check', 'nights'));
                    if ($nights > 0 && $nights % $every === 0 && $automations->enabled($hotel, 'open_stay_check')
                        && $automations->once($hotel, 'open_stay_check', "r{$r->id}-n{$nights}")) {
                        $automations->tell($hotel, 'open_stay_check', $automations->recipients($hotel, 'open_stay_check', $r->branch_id),
                            "Open stay: {$nights} nights for {$r->code}", "{$who} · ask the guest how long they stay, and take a payment.", "/reservations/{$r->id}");
                    }
                } catch (Throwable $e) {
                    report($e);
                } finally {
                    $lock->release();
                }
            });

        return $added;
    }
}

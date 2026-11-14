<?php

namespace App\Services\Feedback;

use App\Models\GuestFeedback;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\Automation\Automations;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guest feedback (owner, 14 Nov 2026): a secure link per stay (/f/{secret}, no account, no cookies), open 60 days,
 * one answer. A rating of 3 or less tells the managers at once.
 */
class FeedbackLinks
{
    public const DAYS = 60;

    /** The stay's link (made the first time). Only for a stay whose guest said yes. */
    public function linkFor(Reservation $r): ?string
    {
        if (! $r->feedback_consent || ! in_array($r->status, ['checked_in', 'checked_out'], true)) {
            return null;
        }
        $f = GuestFeedback::where('reservation_id', $r->id)->first() ?? $this->create($r);

        return $f->submitted_at || $f->expires_at->isPast() ? null : rtrim((string) config('app.url'), '/').'/f/'.$f->secret;
    }

    public function find(string $secret): ?GuestFeedback
    {
        return GuestFeedback::withoutGlobalScope('hotel')->where('token', hash('sha256', $secret))->first();
    }

    /** The guest's answer (once). @param array<string, mixed> $data */
    public function submit(Hotel $hotel, GuestFeedback $f, array $data, string $lang): void
    {
        if ($f->submitted_at) {
            throw ValidationException::withMessages(['form' => __('Thank you – your feedback was already sent.')]);
        }
        $ratings = array_intersect_key($data, array_flip(GuestFeedback::RATINGS));
        $r = Reservation::with('rooms')->find($f->reservation_id);
        $f->update($ratings + [
            'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
            'public_ok' => ! empty($data['public_ok']), 'language' => $lang, 'submitted_at' => now(),
            'checked_in_by' => $r?->checked_in_by, 'checked_out_by' => $r?->checked_out_by,
            'room_id' => $r?->rooms->first()?->room_id, 'branch_id' => $r?->branch_id,
        ]);
        if ((int) $f->overall <= 3) {
            $a = app(Automations::class);
            if ($a->enabled($hotel, 'low_rating')) {
                $name = $f->guest?->full_name ?? '';
                $a->tell($hotel, 'low_rating', $a->recipients($hotel, 'low_rating', $r?->branch_id), "Low rating: {$f->overall} of 5".($name ? " from {$name}" : ''),
                    $f->comment ? mb_substr($f->comment, 0, 160) : 'No comment.', '/feedback?id='.$f->id);
            }
        }
    }

    private function create(Reservation $r): GuestFeedback
    {
        $secret = Str::random(40);

        return GuestFeedback::create([
            'reservation_id' => $r->id, 'guest_id' => $r->guest_id, 'branch_id' => $r->branch_id,
            'token' => hash('sha256', $secret), 'secret' => $secret, 'expires_at' => now()->addDays(self::DAYS),
        ]);
    }
}

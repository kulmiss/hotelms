<?php

namespace App\Services\Watchdog;

use App\Http\Controllers\Api\Admin\SystemController;
use App\Mail\ReportMail;
use App\Models\AppNotification;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WatchdogFinding;
use App\Services\Backup\Backups;
use App\Services\Notify\Telegram;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Watchdog for the HotelMS team: plain rules over the system's own records, run every 10 minutes.
 * Each check says what is wrong, where (hotel, person, page) and what to do. A finding stays open until the check
 * no longer sees it (then it closes by itself) or someone marks it fixed or mutes it.
 *
 * Security: many failed sign-ins, someone trying to open things they may not see, support sign-ins at night.
 * Fraud: unusual refunds or voids, large cash shortages.
 * Bugs: server errors (with the page and the error), scheduler/backup/disk problems.
 * Leakage: rows of one hotel linked to another hotel's rows, money that does not add up, double-booked rooms.
 */
class Watchdog
{
    /** @var array<string, array<string, mixed>> fingerprint => finding seen in this run */
    private array $seen = [];

    /** @var list<string> checks that ran (only their old findings may close by themselves) */
    private array $ran = [];

    public function __construct(private TenantContext $tenant, private Telegram $telegram) {}

    /** Quick checks (every 10 minutes) and the data checks that read whole tables (every hour). */
    public const QUICK = ['bruteForce', 'snooping', 'supportAtNight', 'serverErrors', 'system'];

    public const DATA = ['mixedHotels', 'moneyMismatch', 'doubleBooked', 'refundSpike', 'cashShort'];

    /** Runs the checks; returns the open findings. */
    public function run(bool $data = true): Collection
    {
        $this->seen = [];
        $this->ran = [];
        $this->tenant->acrossAllHotels(function () use ($data) {
            foreach ($data ? [...self::QUICK, ...self::DATA] : self::QUICK as $check) {
                try {
                    $this->{$check}();
                    $this->ran[] = $check;
                } catch (Throwable $e) {
                    report($e); // one broken check never stops the others
                }
            }
        });
        $this->save();
        DB::table('watch_events')->where('created_at', '<', now()->subDays(14))->delete();

        return WatchdogFinding::whereNull('resolved_at')->get();
    }

    /** @param list<array{0: string, 1: string|int|float|null}> $details */
    private function found(string $check, string $key, string $severity, string $title, array $details, string $advice, ?string $link = null, ?int $hotelId = null): void
    {
        $this->seen["{$check}:{$key}"] = [
            'check' => $check, 'severity' => $severity, 'title' => mb_substr($title, 0, 250), 'hotel_id' => $hotelId, 'link' => $link,
            'details' => array_map(fn ($d) => [(string) $d[0], (string) ($d[1] ?? '—')], $details), 'advice' => $advice,
        ];
    }

    private function hotelName(?int $id): string
    {
        return $id ? (string) (DB::table('hotels')->where('id', $id)->value('name') ?? "#{$id}") : 'HotelMS';
    }

    private function userLabel(?int $id): string
    {
        $u = $id ? DB::table('users')->where('id', $id)->first(['name', 'email', 'hotel_id', 'is_super_admin']) : null;

        return $u ? "{$u->name} ({$u->email})".($u->is_super_admin ? ' – HotelMS team' : ' – '.$this->hotelName($u->hotel_id)) : 'Unknown';
    }

    // ------------------------------------------------------------------ security

    /** Many failed sign-ins from one address or for one email in 15 minutes: someone guessing passwords. */
    private function bruteForce(): void
    {
        $since = now()->subMinutes(15);
        $byIp = DB::table('watch_events')->where('kind', 'login_failed')->where('created_at', '>=', $since)
            ->selectRaw('ip, COUNT(*) as n, COUNT(DISTINCT message) as emails')->groupBy('ip')->having('n', '>=', 10)->get();
        foreach ($byIp as $r) {
            $this->found('brute_force', 'ip:'.$r->ip, 'critical', "Many failed sign-ins from {$r->ip}", [
                ['Address (IP)', $r->ip], ['Failed sign-ins (15 min)', $r->n], ['Different emails tried', $r->emails],
            ], 'Someone may be guessing passwords. If the address is not one of your hotels, block it at the hosting firewall. Sign-ins are already slowed down after a few tries.');
        }
        $byEmail = DB::table('watch_events')->where('kind', 'login_failed')->where('created_at', '>=', $since)
            ->selectRaw('message, COUNT(*) as n, COUNT(DISTINCT ip) as ips')->groupBy('message')->having('n', '>=', 8)->get();
        foreach ($byEmail as $r) {
            $user = User::where('email', $r->message)->first();
            $this->found('brute_force', 'email:'.$r->message, 'critical', "Many failed sign-ins for {$r->message}", [
                ['Account', $user ? $this->userLabel($user->id) : "{$r->message} (no such account)"], ['Failed sign-ins (15 min)', $r->n], ['From addresses', $r->ips],
            ], 'Someone may be trying this account. Ask the person if it was them; if not, reset their password and make sure two-step sign-in is on.',
                $user?->hotel_id ? "/admin/hotels/{$user->hotel_id}" : null, $user?->hotel_id);
        }
    }

    /** One person refused or "not found" many times in 10 minutes: trying ids that are not theirs (or a broken screen). */
    private function snooping(): void
    {
        $rows = DB::table('watch_events')->whereIn('kind', ['denied', 'missing'])->whereNotNull('user_id')->where('created_at', '>=', now()->subMinutes(10))
            ->selectRaw('user_id, hotel_id, COUNT(*) as n, COUNT(DISTINCT path) as paths')->groupBy('user_id', 'hotel_id')->having('n', '>=', 25)->get();
        foreach ($rows as $r) {
            $top = DB::table('watch_events')->where('user_id', $r->user_id)->whereIn('kind', ['denied', 'missing'])->where('created_at', '>=', now()->subMinutes(10))
                ->selectRaw('path, COUNT(*) as n')->groupBy('path')->orderByDesc('n')->limit(3)->get()->map(fn ($p) => "{$p->path} ({$p->n}×)")->implode(', ');
            $this->found('snooping', 'user:'.$r->user_id, 'critical', 'Someone is trying to open things they may not see', [
                ['Person', $this->userLabel($r->user_id)], ['Refused / not found (10 min)', $r->n], ['Different pages', $r->paths], ['Most tried', $top],
            ], 'Every attempt was refused, so nothing leaked. If many different numbers were tried, the person may be looking for other hotels\' data: talk to the hotel, or disable the account. If it is always the same page, a screen may be broken.',
                $r->hotel_id ? "/admin/hotels/{$r->hotel_id}" : '/admin/team', $r->hotel_id);
        }
    }

    /** Support sign-ins into hotels at night (22:00–06:00), many in a day, or left open for hours. */
    private function supportAtNight(): void
    {
        $logs = DB::table('impersonation_logs')->where('started_at', '>=', now()->subDay())->get();
        foreach ($logs as $l) {
            $hour = (int) Carbon::parse($l->started_at, 'UTC')->setTimezone('Africa/Addis_Ababa')->format('G');
            $long = ! $l->ended_at && Carbon::parse($l->started_at, 'UTC')->lt(now()->subHours(2));
            if ($hour >= 22 || $hour < 6 || $long) {
                $this->found('support_sign_in', 'log:'.$l->id, 'warning', $long ? 'A support sign-in into a hotel is still open after 2 hours' : 'Support signed in to a hotel at night', [
                    ['By', $this->userLabel($l->super_admin_id)], ['Hotel', $this->hotelName($l->hotel_id)], ['As', $this->userLabel($l->impersonated_user_id)],
                    ['Started (Addis Ababa)', Carbon::parse($l->started_at, 'UTC')->setTimezone('Africa/Addis_Ababa')->format('d M Y H:i')], ['Reason given', $l->reason],
                ], 'Check with the team member that this was planned. Support sign-ins are written in the hotel\'s own activity log too.', "/admin/hotels/{$l->hotel_id}", $l->hotel_id);
            }
        }
        $many = $logs->groupBy('super_admin_id')->filter(fn ($l) => $l->count() >= 8);
        foreach ($many as $adminId => $l) {
            $this->found('support_sign_in', 'many:'.$adminId.':'.now()->toDateString(), 'warning', 'Many support sign-ins into hotels today', [
                ['By', $this->userLabel((int) $adminId)], ['Sign-ins (24 h)', $l->count()], ['Hotels', $l->pluck('hotel_id')->unique()->count()],
            ], 'Unusual for one person. Check the reasons in the platform activity log.', '/admin/activity');
        }
    }

    // ------------------------------------------------------------------ bugs and system

    /** Server errors in the last hour, grouped by error and page. */
    private function serverErrors(): void
    {
        $rows = DB::table('watch_events')->where('kind', 'exception')->where('created_at', '>=', now()->subHour())
            ->selectRaw('message, COUNT(*) as n, COUNT(DISTINCT user_id) as people, COUNT(DISTINCT hotel_id) as hotels, MAX(path) as path, MAX(created_at) as last')
            ->groupBy('message')->orderByDesc('n')->limit(20)->get();
        foreach ($rows as $r) {
            $this->found('server_error', 'msg:'.sha1((string) $r->message), $r->n >= 20 || $r->hotels >= 3 ? 'critical' : 'warning', 'Server error: '.mb_substr((string) $r->message, 0, 140), [
                ['Error and place in the code', $r->message], ['Page (API)', $r->path], ['Times (1 h)', $r->n], ['People affected', $r->people], ['Hotels affected', $r->hotels],
                ['Last time', Carbon::parse($r->last, 'UTC')->setTimezone('Africa/Addis_Ababa')->format('d M H:i')],
            ], 'A bug: people saw an error on this page. Send this to the developer (Claude) with the page name. The full error is in the server log (System health → Latest errors).', '/admin/system');
        }
        $total = DB::table('watch_events')->where('kind', 'error')->where('created_at', '>=', now()->subMinutes(15))->count();
        if ($total >= 30) {
            $this->found('server_error', 'many', 'critical', "{$total} server errors in 15 minutes", [['Errors (15 min)', $total]],
                'Something is broken for many people (database down, full disk, bad update?). Open System health first.', '/admin/system');
        }
    }

    /** Scheduler, background jobs, backups and disk (the same checks as System health, but they warn you). */
    private function system(): void
    {
        $beat = Cache::get(SystemController::HEARTBEAT);
        if (! $beat || Carbon::parse($beat)->lt(now()->subMinutes(10))) {
            $this->found('system', 'scheduler', 'critical', 'The cron job (scheduler) is not running', [['Last run', $beat ? Carbon::parse($beat)->format('d M H:i') : 'never']],
                'Bookings do not expire, reminders and backups stop. Check the cron line in cPanel (see docs/hosting.md).', '/admin/system');
        }
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        if ($failed > 0) {
            $this->found('system', 'failed_jobs', 'warning', "{$failed} background jobs failed today", [['Failed (24 h)', $failed]],
                'Emails or reports may not have gone out. See System health → Background jobs.', '/admin/system');
        }
        if (! app()->environment('testing')) {
            $folder = app(Backups::class)->folder();
            $files = glob($folder.'/daily/*.sql.gz') ?: [];
            $newest = $files ? max(array_map('filemtime', $files)) : null;
            if (! $newest || $newest < now()->subHours(26)->timestamp) {
                $this->found('system', 'backup', 'critical', 'No backup in the last day', [['Newest daily backup', $newest ? date('d M Y H:i', $newest) : 'none'], ['Folder', $folder]],
                    'If the server fails, data since the last backup is lost. Check the cron job and BACKUP_PATH.', '/admin/system');
            }
            $free = @disk_free_space(storage_path());
            if ($free !== false && $free < 1024 * 1024 * 1024) {
                $this->found('system', 'disk', 'critical', 'Disk almost full', [['Free', round($free / 1024 / 1024).' MB']],
                    'When the disk is full nothing can be saved. Free space or get a bigger plan from the host.', '/admin/system');
            }
        }
    }

    // ------------------------------------------------------------------ leakage and data that does not add up

    /** Rows of one hotel pointing at another hotel's rows: should never happen; a bug or tampering. */
    private function mixedHotels(): void
    {
        $pairs = [
            ['reservations', 'branch_id', 'branches', 'Booking in another hotel\'s branch'],
            ['reservations', 'guest_id', 'guests', 'Booking with another hotel\'s guest'],
            ['reservation_rooms', 'reservation_id', 'reservations', 'Booked room of another hotel\'s booking'],
            ['reservation_rooms', 'room_id', 'rooms', 'Booking in another hotel\'s room'],
            ['invoices', 'reservation_id', 'reservations', 'Bill for another hotel\'s booking'],
            ['payments', 'reservation_id', 'reservations', 'Payment for another hotel\'s booking'],
            ['payments', 'invoice_id', 'invoices', 'Payment on another hotel\'s bill'],
            ['payments', 'payment_method_id', 'payment_methods', 'Payment with another hotel\'s payment method'],
            ['rooms', 'branch_id', 'branches', 'Room in another hotel\'s branch'],
            ['rooms', 'room_type_id', 'room_types', 'Room with another hotel\'s room type'],
            ['housekeeping_tasks', 'room_id', 'rooms', 'Cleaning task for another hotel\'s room'],
            ['cashier_sessions', 'user_id', 'users', 'Cashier shift of another hotel\'s staff'],
            ['users', 'role_id', 'roles', 'Staff with another hotel\'s role'],
            ['payment_proofs', 'payment_id', 'payments', 'Payment proof of another hotel\'s payment'],
            ['guest_id_photos', 'guest_id', 'guests', 'ID photo of another hotel\'s guest'],
        ];
        foreach ($pairs as [$table, $column, $parent, $label]) {
            $rows = DB::table("{$table} as c")->join("{$parent} as p", 'p.id', '=', "c.{$column}")
                ->whereNotNull('c.hotel_id')->whereColumn('c.hotel_id', '!=', 'p.hotel_id')
                ->selectRaw('c.hotel_id as hotel, p.hotel_id as other, COUNT(*) as n, MIN(c.id) as example')->groupBy('c.hotel_id', 'p.hotel_id')->get();
            foreach ($rows as $r) {
                $this->found('mixed_hotels', "{$table}.{$column}:{$r->hotel}:{$r->other}", 'critical', "Data of two hotels mixed: {$label}", [
                    ['Table', "{$table}.{$column} → {$parent}"], ['Hotel', $this->hotelName($r->hotel)], ['Points at hotel', $this->hotelName($r->other)], ['Rows', $r->n], ['Example row id', $r->example],
                ], 'A possible data leak between hotels. Do not change the rows by hand: send this to the developer (Claude) at once. The rows are hidden from the other hotel by the hotel filter, but they must be repaired.', "/admin/hotels/{$r->hotel}", (int) $r->hotel);
            }
        }
        // A person working in a branch of another hotel.
        $wrong = DB::table('branch_user as bu')->join('users as u', 'u.id', '=', 'bu.user_id')->join('branches as b', 'b.id', '=', 'bu.branch_id')
            ->whereColumn('u.hotel_id', '!=', 'b.hotel_id')->selectRaw('u.id, u.hotel_id, b.hotel_id as other')->get();
        foreach ($wrong as $w) {
            $this->found('mixed_hotels', "branch_user:{$w->id}:{$w->other}", 'critical', 'A staff member is linked to another hotel\'s branch', [
                ['Person', $this->userLabel($w->id)], ['Branch of hotel', $this->hotelName($w->other)],
            ], 'The person cannot open that branch (access is checked by hotel too), but the link is wrong. Send this to the developer (Claude).', "/admin/hotels/{$w->hotel_id}", (int) $w->hotel_id);
        }
    }

    /** Bills whose "paid" does not match their payments, and bookings paid more than their total. */
    private function moneyMismatch(): void
    {
        $rows = DB::table('invoices as i')->leftJoin('payments as p', 'p.invoice_id', '=', 'i.id')->where('i.status', '!=', 'void')
            ->where('i.updated_at', '>=', now()->subDays(30))
            ->groupBy('i.id', 'i.hotel_id', 'i.number', 'i.paid_total', 'i.reservation_id')
            ->selectRaw("i.id, i.hotel_id, i.number, i.paid_total, i.reservation_id, COALESCE(SUM(CASE WHEN p.type = 'refund' THEN -p.amount_in_base ELSE p.amount_in_base END), 0) as payments")
            ->havingRaw("ABS(i.paid_total - COALESCE(SUM(CASE WHEN p.type = 'refund' THEN -p.amount_in_base ELSE p.amount_in_base END), 0)) >= 0.01")->limit(50)->get();
        foreach ($rows->groupBy('hotel_id') as $hotelId => $bills) {
            $first = $bills->first();
            $this->found('money', "bills:{$hotelId}", 'warning', 'Bills where "paid" does not match the payments', [
                ['Hotel', $this->hotelName((int) $hotelId)], ['Bills', $bills->count()], ['Example', "{$first->number}: paid {$first->paid_total}, payments {$first->payments}"],
            ], 'A bug in the money code or a change made outside the app. Send this to the developer (Claude); do not edit the bills.', "/admin/hotels/{$hotelId}", (int) $hotelId);
        }
    }

    /** Two guests checked in to the same room at the same time, or an "occupied" room with nobody in it. */
    private function doubleBooked(): void
    {
        $double = DB::table('reservation_rooms')->where('status', 'checked_in')->whereNotNull('room_id')
            ->selectRaw('room_id, hotel_id, COUNT(*) as n')->groupBy('room_id', 'hotel_id')->having('n', '>', 1)->get();
        foreach ($double as $d) {
            $number = DB::table('rooms')->where('id', $d->room_id)->value('number');
            $this->found('rooms', "double:{$d->room_id}", 'warning', "Room {$number}: {$d->n} guests checked in at once", [
                ['Hotel', $this->hotelName($d->hotel_id)], ['Room', $number], ['Stays checked in', $d->n],
            ], 'One of the stays was probably not checked out. Ask the hotel to check the room on the room board.', "/admin/hotels/{$d->hotel_id}", (int) $d->hotel_id);
        }
    }

    // ------------------------------------------------------------------ fraud

    /** Many refunds or voided bills in a hotel today, compared with its usual day. */
    private function refundSpike(): void
    {
        $today = now()->subDay();
        $rows = DB::table('payments')->where('type', 'refund')->where('paid_at', '>=', $today)->selectRaw('hotel_id, COUNT(*) as n, SUM(amount_in_base) as total')->groupBy('hotel_id')->get();
        foreach ($rows as $r) {
            $usual = DB::table('payments')->where('hotel_id', $r->hotel_id)->where('type', 'refund')->whereBetween('paid_at', [now()->subDays(31), $today])->count() / 30;
            if ($r->n >= 3 && $r->n > 3 * max(1, $usual)) {
                $this->found('fraud', "refunds:{$r->hotel_id}:".now()->toDateString(), 'warning', 'Unusual number of refunds today', [
                    ['Hotel', $this->hotelName($r->hotel_id)], ['Refunds (24 h)', $r->n], ['Amount', number_format((float) $r->total, 2)], ['Usual per day', round($usual, 1)],
                ], 'Could be normal (a cancelled group) or misuse. The hotel\'s Staff audit report shows who did them.', "/admin/hotels/{$r->hotel_id}", (int) $r->hotel_id);
            }
        }
        $voids = DB::table('invoices')->where('status', 'void')->where('voided_at', '>=', $today)->selectRaw('hotel_id, COUNT(*) as n')->groupBy('hotel_id')->having('n', '>=', 3)->get();
        foreach ($voids as $v) {
            $this->found('fraud', "voids:{$v->hotel_id}:".now()->toDateString(), 'warning', 'Several bills cancelled today', [['Hotel', $this->hotelName($v->hotel_id)], ['Voided bills (24 h)', $v->n]],
                'Cancelling bills after taking money can hide theft. The hotel\'s Staff audit report shows who cancelled them.', "/admin/hotels/{$v->hotel_id}", (int) $v->hotel_id);
        }
    }

    /** A cashier shift closed with a large shortage. */
    private function cashShort(): void
    {
        $rows = DB::table('cashier_session_balances as b')->join('cashier_sessions as s', 's.id', '=', 'b.cashier_session_id')
            ->where('s.closed_at', '>=', now()->subDay())->where('b.difference', '<=', -2000)->get(['s.id', 's.hotel_id', 's.user_id', 'b.currency', 'b.difference']);
        foreach ($rows as $r) {
            $this->found('fraud', "cash:{$r->id}:{$r->currency}", 'warning', 'Large cash shortage at the end of a cashier shift', [
                ['Hotel', $this->hotelName($r->hotel_id)], ['Cashier', $this->userLabel($r->user_id)], ['Short', "{$r->currency} ".number_format(abs((float) $r->difference), 2)],
            ], 'The hotel\'s manager is told too. Ask the hotel how it was explained.', "/admin/hotels/{$r->hotel_id}", (int) $r->hotel_id);
        }
    }

    // ------------------------------------------------------------------ saving and telling

    private function save(): void
    {
        $now = now();
        $new = [];
        foreach ($this->seen as $fingerprint => $f) {
            $row = WatchdogFinding::where('fingerprint', $fingerprint)->first();
            if ($row) {
                $reopened = $row->resolved_at !== null;
                $row->update(['severity' => $f['severity'], 'title' => $f['title'], 'details' => $f['details'], 'advice' => $f['advice'], 'link' => $f['link'], 'hotel_id' => $f['hotel_id'],
                    'last_seen_at' => $now, 'times' => $row->times + 1, 'resolved_at' => null, 'resolved_by' => null] + ($reopened ? ['notified_at' => null] : []));
            } else {
                $row = WatchdogFinding::create($f + ['fingerprint' => $fingerprint, 'first_seen_at' => $now, 'last_seen_at' => $now, 'times' => 1]);
            }
            if (! $row->notified_at && (! $row->muted_until || $row->muted_until->lt($now))) {
                $new[] = $row;
            }
        }
        // Not seen any more by a check that ran: fixed (closes by itself).
        WatchdogFinding::whereNull('resolved_at')->whereIn('check', $this->checkKeys())->whereNotIn('fingerprint', array_keys($this->seen) ?: [''])
            ->update(['resolved_at' => $now, 'resolved_by' => null]);
        $this->tell($new);
    }

    /** @return list<string> the finding "check" names of the checks that ran */
    private function checkKeys(): array
    {
        $map = ['bruteForce' => 'brute_force', 'snooping' => 'snooping', 'supportAtNight' => 'support_sign_in', 'serverErrors' => 'server_error', 'system' => 'system',
            'mixedHotels' => 'mixed_hotels', 'moneyMismatch' => 'money', 'doubleBooked' => 'rooms', 'refundSpike' => 'fraud', 'cashShort' => 'fraud'];

        return array_values(array_unique(array_map(fn ($c) => $map[$c], $this->ran)));
    }

    /** Bell for every new finding; Telegram and email too when it is critical. @param list<WatchdogFinding> $findings */
    private function tell(array $findings): void
    {
        if (! $findings) {
            return;
        }
        $team = User::where('is_super_admin', true)->where('status', 'active')->get()->filter(fn (User $u) => $u->hasPlatformPermission('watchdog.view'));
        foreach ($findings as $f) {
            $critical = $f->severity === 'critical';
            $where = $f->hotel_id ? ' · '.$this->hotelName($f->hotel_id) : '';
            foreach ($team as $u) {
                AppNotification::create(['hotel_id' => null, 'user_id' => $u->id, 'source' => 'watchdog', 'title' => mb_substr(($critical ? '❗ ' : '').$f->title, 0, 200),
                    'body' => mb_substr($f->advice ?? '', 0, 500), 'link' => '/admin/watchdog', 'urgent' => $critical, 'created_at' => now()]);
                if ($critical) {
                    try {
                        if ($this->telegram->enabled() && ($chat = $this->telegram->chatFor($u))) {
                            $this->telegram->send($chat, "❗ Watchdog: {$f->title}{$where}\n".($f->advice ?? '')."\n".rtrim((string) config('app.frontend_url'), '/').'/admin/watchdog');
                        }
                        Mail::to($u->email)->send(new ReportMail('HotelMS', 'Watchdog: '.$f->title, array_merge(
                            array_map(fn ($d) => "{$d[0]}: {$d[1]}", $f->details ?? []), ['', (string) $f->advice],
                        ), null, null, rtrim((string) config('app.frontend_url'), '/').'/admin/watchdog'));
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }
            $f->update(['notified_at' => now()]);
        }
    }
}

<?php

namespace App\Services\Automation;

use App\Models\AppNotification;
use App\Models\AutomationSetting;
use App\Models\Hotel;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\HotelModules;
use App\Services\HotelSettings;
use App\Services\Notify\Telegram;
use App\Services\Switches;
use App\Support\HotelClock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ready-made automations (automation.md): on/off per hotel and per Super Admin switch, who is told,
 * once-only firing, quiet hours, and delivery (in-app always, Telegram when linked).
 * Every automatic message is written to the activity log ("done by automation").
 */
class Automations
{
    /** @var array<int, array<string, array{enabled: bool, options: array<string, mixed>}>> */
    private array $memo = [];

    public function __construct(
        private Switches $switches,
        private Telegram $telegram,
        private ActivityLogger $log,
        private TenantContext $tenant,
        private HotelModules $modules,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public static function registry(): array
    {
        return config('automations', []);
    }

    /** The hotel's on/off and numbers for every ready-made automation. @return array<string, array{enabled: bool, options: array<string, mixed>}> */
    public function settings(Hotel $hotel): array
    {
        if (isset($this->memo[$hotel->id])) {
            return $this->memo[$hotel->id];
        }
        $saved = $this->tenant->acrossAllHotels(fn () => AutomationSetting::where('hotel_id', $hotel->id)->get()->keyBy('key'));
        $out = [];
        foreach (self::registry() as $key => $def) {
            $row = $saved[$key] ?? null;
            $out[$key] = [
                'enabled' => $row ? (bool) $row->enabled : (bool) $def['default'],
                'options' => array_merge($def['options'] ?? [], array_intersect_key($row?->options ?? [], $def['options'] ?? [])),
            ];
        }

        return $this->memo[$hotel->id] = $out;
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** Is this automation working for the hotel? (Super Admin switches → module → the hotel's own on/off) */
    public function enabled(?Hotel $hotel, string $key): bool
    {
        $def = self::registry()[$key] ?? null;
        if (! $def || ($hotel?->is_demo && empty($def['platform']))) {
            return false; // the demo hotel never sends messages
        }
        if (! $this->switches->on($hotel, 'feature.automations') || ! $this->switches->on($hotel, "automation.{$key}")) {
            return false;
        }
        if (! $hotel || ! empty($def['platform'])) {
            return true;
        }
        if (! empty($def['module']) && ! in_array($def['module'], $this->modules->enabled($hotel), true)) {
            return false;
        }

        return $this->settings($hotel)[$key]['enabled'];
    }

    public function option(Hotel $hotel, string $key, string $name): mixed
    {
        return $this->settings($hotel)[$key]['options'][$name] ?? (self::registry()[$key]['options'][$name] ?? null);
    }

    /** True the first time only (per hotel, automation and subject), so nobody gets the same message twice. */
    public function once(Hotel $hotel, string $key, string $subject): bool
    {
        return DB::table('automation_firings')->insertOrIgnore([
            'hotel_id' => $hotel?->id, 'key' => $key, 'subject' => mb_substr($subject, 0, 120), 'fired_at' => now(),
        ]) > 0;
    }

    /**
     * Hotel staff to tell: anyone whose role has one of the permissions (the Hotel Admin has all),
     * limited to a branch when given. "hotel_admin" = Hotel Admins only.
     *
     * @param  list<string>  $to
     * @return Collection<int, User>
     */
    public function people(Hotel $hotel, array $to, ?int $branchId = null): Collection
    {
        return $this->tenant->runAs($hotel, function () use ($hotel, $to, $branchId) {
            return User::where('hotel_id', $hotel->id)->where('status', 'active')->with('role')->get()
                ->filter(function (User $u) use ($to, $branchId) {
                    if ($u->isHotelAdmin()) {
                        return true;
                    }
                    if (! collect($to)->contains(fn ($p) => $p !== 'hotel_admin' && $u->hasPermission($p))) {
                        return false;
                    }

                    return $branchId === null || $u->branches()->whereKey($branchId)->exists();
                })->values();
        });
    }

    /** People from the registry's "to" list (permissions only; special recipients are added by the caller). @return Collection<int, User> */
    public function recipients(Hotel $hotel, string $key, ?int $branchId = null): Collection
    {
        $to = array_values(array_filter(self::registry()[$key]['to'] ?? [], fn ($p) => str_contains($p, '.') || $p === 'hotel_admin'));

        return $to ? $this->people($hotel, $to, $branchId) : collect();
    }

    public function isQuiet(Hotel $hotel): bool
    {
        $q = app(HotelSettings::class)->group(HotelSettings::QUIET_DEFAULTS);
        if (! $q['quiet_hours']) {
            return false;
        }
        $now = HotelClock::now($hotel)->format('H:i');
        [$from, $to] = [(string) $q['quiet_from'], (string) $q['quiet_to']];

        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }

    /**
     * Tells people: always in the app (bell), on Telegram too when they linked it — not in quiet hours unless urgent.
     *
     * @param  iterable<User>  $users
     */
    public function tell(?Hotel $hotel, string $source, iterable $users, string $title, ?string $body = null, ?string $link = null, ?bool $urgent = null): int
    {
        $urgent ??= (bool) (self::registry()[$source]['urgent'] ?? false);
        $users = collect($users)->unique('id')->values();
        if ($users->isEmpty()) {
            return 0;
        }
        $quiet = $hotel && $this->tenant->runAs($hotel, fn () => $this->isQuiet($hotel));
        $telegram = $this->telegram->enabled() && $this->switches->on($hotel, 'feature.telegram') && (! $quiet || $urgent);

        foreach ($users as $user) {
            AppNotification::create([
                'hotel_id' => $hotel?->id, 'user_id' => $user->id, 'source' => mb_substr($source, 0, 60), 'title' => mb_substr($title, 0, 200),
                'body' => $body, 'link' => $link, 'urgent' => $urgent, 'created_at' => now(),
            ]);
            if ($telegram && ($chat = $this->telegram->chatFor($user))) {
                try {
                    $url = $link ? rtrim((string) config('app.frontend_url'), '/').$link : null;
                    $this->telegram->send($chat, trim(($urgent ? '❗ ' : '').$title.($hotel ? " · {$hotel->name}" : '')."\n".($body ?? '').($url ? "\n{$url}" : '')));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
        $this->tenant->acrossAllHotels(fn () => $this->log->log('automation.fired', "Done by automation: {$title}", null, ['automation' => $source, 'to' => $users->pluck('name')->all()], $hotel?->id));

        return $users->count();
    }
}

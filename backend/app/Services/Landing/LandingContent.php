<?php

namespace App\Services\Landing;

use App\Models\FeatureSwitch;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Services\Signup\SelfSignup;
use App\Services\Switches;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The public website (owner, 14 Nov 2026): words and pictures from Super Admin → Content → Website
 * (`landing` platform setting) over these defaults, and the plan cards from the database.
 * Only true claims: no invented customers, logos, numbers or reviews.
 */
class LandingContent
{
    public const CACHE = 'landing:v1';

    /** @var Collection<int, FeatureSwitch>|null */
    private ?Collection $switchRows = null;

    public const SECTIONS = ['trust', 'features', 'how', 'gallery', 'plans', 'security', 'faq', 'testimonials', 'cta'];

    /** Pictures that come with HotelMS (real screens of the demo hotel); Super Admin can replace them. */
    public const PICTURES = [
        'hero' => ['src' => '/landing/dashboard.webp', 'srcset' => '/landing/dashboard-640.webp 640w, /landing/dashboard-960.webp 960w, /landing/dashboard.webp 1280w', 'w' => 1280, 'h' => 800, 'alt' => 'The dashboard: occupancy, revenue, arrivals and what needs attention today'],
        'gallery' => [
            ['src' => '/landing/room-board.webp', 'srcset' => '/landing/room-board-640.webp 640w, /landing/room-board.webp 1280w', 'w' => 1280, 'h' => 800, 'alt' => 'The room board: every room and its status at a glance', 'caption' => 'Room board'],
            ['src' => '/landing/invoice.webp', 'srcset' => '/landing/invoice-640.webp 640w, /landing/invoice.webp 1280w', 'w' => 1280, 'h' => 800, 'alt' => 'A guest bill with VAT, payments and the balance', 'caption' => 'Bills and payments'],
            ['src' => '/landing/reports.webp', 'srcset' => '/landing/reports-640.webp 640w, /landing/reports.webp 1280w', 'w' => 1280, 'h' => 800, 'alt' => 'Reports with occupancy and revenue', 'caption' => 'Reports'],
            ['src' => '/landing/phone.webp', 'w' => 390, 'h' => 844, 'alt' => 'The front desk on a phone', 'caption' => 'On the phone', 'phone' => true],
        ],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'headline' => 'Run your hotel from one simple screen',
            'subline' => 'Bookings, front desk, bills, housekeeping and reports for Ethiopian hotels and guesthouses. Start free – no card needed.',
            'sections' => array_fill_keys(self::SECTIONS, true) + ['testimonials' => false],
            'faq' => [
                ['q' => 'Is the Free plan really free?', 'a' => 'Yes. The Free plan costs nothing and has no time limit. It includes the daily work of a small hotel: bookings, front desk, check-in and check-out, bills, guests, housekeeping and payment proofs.'],
                ['q' => 'Do I need a card or a contract?', 'a' => 'No. There is no online payment. When you want a bigger plan, contact us and we send you an invoice.'],
                ['q' => 'What happens when I reach a limit?', 'a' => 'The app tells you in a friendly message and shows how to upgrade. Nothing is deleted and your daily work keeps going.'],
                ['q' => 'Can I change my plan later?', 'a' => 'Yes, up or down at any time. Your data stays as it is.'],
                ['q' => 'Does it work on a phone and with weak internet?', 'a' => 'Yes. Every screen works on phones and tablets, and the latest room board and bookings stay viewable when the internet is down for a while.'],
                ['q' => 'Who owns the guest data?', 'a' => 'Your hotel does. We only keep it safe for you, never sell it and never show ads. You can download all your data at any time.'],
                ['q' => 'How do I get help?', 'a' => 'Send a message from Help → Ask us in the app, or contact us on WhatsApp or by email.'],
            ],
            'testimonials' => [],
            'pictures' => self::PICTURES,
        ];
    }

    /** Everything the page needs (cached a minute; cleared when Super Admin saves). @return array<string, mixed> */
    public function page(): array
    {
        return Cache::remember(self::CACHE.':'.app()->getLocale(), 60, fn () => $this->build());
    }

    public static function forget(): void
    {
        foreach (['en', 'am'] as $lang) {
            Cache::forget(self::CACHE.':'.$lang);
        }
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $saved = PlatformSetting::read('landing');
        $c = self::merge(self::defaults(), is_array($saved) ? $saved : []);
        $c['testimonials'] = array_values(array_filter($c['testimonials'], fn ($t) => filled($t['quote'] ?? null) && filled($t['name'] ?? null)));
        if (! $c['testimonials']) {
            $c['sections']['testimonials'] = false; // never an empty or invented section
        }
        $plans = $this->plans();
        $c['plans'] = $plans->map(fn (Plan $p) => $this->card($p))->values()->all();
        $c['compare'] = $this->compare($plans);
        $c['signup_mode'] = SelfSignup::mode();
        $c['platform_name'] = (string) PlatformSetting::read('platform_name');
        $c['brand_color'] = (string) PlatformSetting::read('brand_color');
        $c['demo'] = (bool) PlatformSetting::read('demo_public');
        $wa = preg_replace('/\D+/', '', (string) PlatformSetting::read('whatsapp'));
        $c['whatsapp'] = $wa !== '' ? ['href' => "https://wa.me/{$wa}", 'label' => (string) PlatformSetting::read('whatsapp')] : null;
        $c['email'] = PlatformSetting::read('support_email') ?: null;

        return $c;
    }

    /** Saved values over defaults: lists are replaced, sections and pictures merged key by key. */
    public static function merge(array $defaults, array $saved): array
    {
        $out = $defaults;
        foreach (['headline', 'subline'] as $k) {
            if (filled($saved[$k] ?? null)) {
                $out[$k] = (string) $saved[$k];
            }
        }
        foreach (self::SECTIONS as $s) {
            if (isset($saved['sections'][$s])) {
                $out['sections'][$s] = (bool) $saved['sections'][$s];
            }
        }
        foreach (['faq', 'testimonials'] as $k) {
            if (isset($saved[$k]) && is_array($saved[$k])) {
                $out[$k] = array_values($saved[$k]);
            }
        }
        if (! empty($saved['pictures']['hero']['src'])) {
            $out['pictures']['hero'] = $saved['pictures']['hero'] + ['srcset' => null] + $defaults['pictures']['hero'];
        }
        foreach ($saved['pictures']['gallery'] ?? [] as $i => $pic) {
            if (! empty($pic['src']) && isset($out['pictures']['gallery'][$i])) {
                $out['pictures']['gallery'][$i] = $pic + ['srcset' => null] + $out['pictures']['gallery'][$i];
            }
        }

        return $out;
    }

    /** @return Collection<int, Plan> */
    private function plans(): Collection
    {
        return Plan::where('is_public', true)->where('is_active', true)->orderBy('sort')->get();
    }

    /** @return array<string, mixed> */
    private function card(Plan $p): array
    {
        $monthly = (float) $p->minimum_monthly;
        $months = 12 - (int) $p->yearly_free_months;

        return [
            'code' => $p->code, 'name' => preg_replace('/\s*\(recommended\)$/i', '', $p->name), 'description' => $p->description,
            'recommended' => (bool) $p->is_recommended, 'currency' => $p->currency ?: 'ETB', 'free' => $p->isFree(),
            'monthly' => number_format($monthly), 'yearly' => number_format($monthly * $months), 'free_months' => (int) $p->yearly_free_months,
            'points' => array_values(array_filter([
                $this->limit($p->max_rooms, 'room'), $this->limit($p->max_staff, 'staff account'),
                $p->max_branches === null ? 'Unlimited properties' : ($p->max_branches > 1 ? __(':n properties', ['n' => $p->max_branches]) : null),
                $p->max_storage_mb ? __(':size of files', ['size' => $this->size($p->max_storage_mb)]) : null,
                $this->on($p, 'feature.full_reports') ? 'Full reports and Excel export' : 'Daily reports',
                $this->on($p, 'feature.telegram') ? 'Telegram daily report' : null,
                in_array('shifts', $p->modules ?? [], true) ? 'Shifts and attendance' : null,
                $this->on($p, 'feature.branding') ? 'Your own brand on the booking page' : null,
            ])),
        ];
    }

    /** The comparison table: one row per thing that differs, from the plans' real limits and switches. */
    private function compare(Collection $plans): array
    {
        $row = fn (string $label, callable $value) => ['label' => $label, 'values' => $plans->map(fn (Plan $p) => $value($p))->all()];
        $yes = fn (bool $b) => $b ? '✓' : '–';

        return [
            'plans' => $plans->map(fn (Plan $p) => preg_replace('/\s*\(recommended\)$/i', '', $p->name))->all(),
            'rows' => [
                $row('Rooms', fn (Plan $p) => $p->max_rooms === null ? 'Unlimited' : (string) $p->max_rooms),
                $row('Properties (branches)', fn (Plan $p) => $p->max_branches === null ? 'Unlimited' : (string) $p->max_branches),
                $row('Staff accounts', fn (Plan $p) => $p->max_staff === null ? 'Unlimited' : (string) $p->max_staff),
                $row('Files (payment proofs, ID photos)', fn (Plan $p) => $p->max_storage_mb ? $this->size($p->max_storage_mb) : 'Unlimited'),
                $row('Bookings, front desk, check-in/out', fn () => '✓'),
                $row('Walk-ins, hourly and day use', fn () => '✓'),
                $row('Bills, payment proofs, printing', fn () => '✓'),
                $row('Housekeeping', fn (Plan $p) => $yes(in_array('housekeeping', $p->modules ?? [], true))),
                $row('Two-step sign-in, offline view', fn () => '✓'),
                $row('Cashier shift close', fn (Plan $p) => $yes(in_array('cashier_close', $p->modules ?? [], true))),
                $row('Several currencies', fn (Plan $p) => $yes($this->on($p, 'feature.multi_currency'))),
                $row('Telegram and daily report', fn (Plan $p) => $yes($this->on($p, 'feature.telegram'))),
                $row('Full reports and Excel export', fn (Plan $p) => $yes($this->on($p, 'feature.full_reports'))),
                $row('Shifts and attendance', fn (Plan $p) => $yes(in_array('shifts', $p->modules ?? [], true))),
                $row('Accountant role', fn (Plan $p) => $yes($this->on($p, 'feature.accountant_role'))),
                $row('Custom reports and automation rules', fn (Plan $p) => $yes($this->on($p, 'feature.custom_reports'))),
                $row('Booking page without "Powered by"', fn (Plan $p) => $yes($this->on($p, 'feature.branding'))),
                $row('Support', fn (Plan $p) => $this->on($p, 'feature.priority_support') ? ($p->max_rooms === null ? 'Priority + phone' : 'Priority') : ($this->on($p, 'feature.telegram') ? 'In the app + Telegram' : 'In the app')),
            ],
        ];
    }

    /** A plan's value of a switch: plan level, then everyone, then the default. */
    private function on(Plan $p, string $key): bool
    {
        $rows = $this->switchRows ??= FeatureSwitch::active()->whereIn('level', ['everyone', 'plan'])->orderByDesc('id')->get();
        $row = $rows->first(fn (FeatureSwitch $r) => $r->key === $key && $r->level === 'plan' && (int) $r->target_id === $p->id)
            ?? $rows->first(fn (FeatureSwitch $r) => $r->key === $key && $r->level === 'everyone');

        return $row ? (bool) $row->value : (bool) (Switches::registry()[$key]['default'] ?? true);
    }

    private function limit(?int $n, string $what): string
    {
        if ($n === null) {
            return $what === 'staff account' ? __('Unlimited staff') : __('Unlimited rooms');
        }

        return $what === 'staff account'
            ? trans_choice('{1} Up to :n staff account|[2,*] Up to :n staff accounts', $n, ['n' => $n])
            : trans_choice('{1} Up to :n room|[2,*] Up to :n rooms', $n, ['n' => $n]);
    }

    private function size(int $mb): string
    {
        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.').' GB' : "{$mb} MB";
    }
}

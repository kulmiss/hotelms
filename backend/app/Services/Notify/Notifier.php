<?php

namespace App\Services\Notify;

use App\Mail\ReportMail;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Switches;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a report or an alert by email and/or Telegram (decisions.md: free channels only).
 * The public demo hotel never sends anything.
 */
class Notifier
{
    public function __construct(private Telegram $telegram) {}

    /**
     * @param  iterable<User>  $users
     * @param  list<string>  $channels  email, telegram
     * @param  list<string>  $extraEmails
     * @param  list<string>  $lines
     * @return list<string> problems (empty when everything was sent)
     */
    public function send(Hotel $hotel, iterable $users, array $channels, array $extraEmails, string $title, array $lines, ?string $fileName = null, ?string $file = null, ?string $link = null): array
    {
        if ($hotel->is_demo) {
            return [];
        }
        if (! $hotel->maySendMessages()) {
            return ['This hotel is not confirmed yet, so nothing is sent.'];
        }
        $problems = [];
        $url = $link ? rtrim((string) config('app.frontend_url'), '/').$link : null;

        if (in_array('email', $channels, true)) {
            $emails = collect($users)->pluck('email')->merge($extraEmails)->filter()->unique()->values();
            foreach ($emails as $email) {
                try {
                    Mail::to($email)->send(new ReportMail($hotel->name, $title, $lines, $fileName, $file, $url));
                } catch (Throwable $e) {
                    $problems[] = "Email to {$email} failed: ".$e->getMessage();
                }
            }
        }

        if (in_array('telegram', $channels, true)) {
            if (! app(Switches::class)->on($hotel, 'feature.telegram')) {
                $problems[] = 'Telegram is switched off for this hotel.';
            } elseif (! $this->telegram->enabled()) {
                $problems[] = 'Telegram is not set up (Super Admin → Settings → Telegram).';
            } else {
                foreach ($users as $user) {
                    $chat = $this->telegram->chatFor($user);
                    if (! $chat) {
                        $problems[] = "{$user->name} has not linked Telegram.";

                        continue;
                    }
                    $text = "{$title} · {$hotel->name}\n".implode("\n", array_map(fn ($l) => "• {$l}", $lines)).($url ? "\n{$url}" : '');
                    $ok = $this->telegram->send($chat, $text);
                    if ($ok && $fileName && $file !== null) {
                        $ok = $this->telegram->sendDocument($chat, $fileName, $file, $title);
                    }
                    if (! $ok) {
                        $problems[] = "Telegram to {$user->name} failed.";
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * An urgent message to the HotelMS team on Telegram (support requests, emergencies).
     * Goes to team members who answer support and have linked Telegram. Never throws.
     */
    public function platform(string $text, string $permission = 'support.answer'): int
    {
        if (! $this->telegram->enabled()) {
            return 0;
        }
        $sent = 0;
        foreach (User::where('is_super_admin', true)->where('status', 'active')->get() as $user) {
            if (! $user->hasPlatformPermission($permission) || ! ($chat = $this->telegram->chatFor($user))) {
                continue;
            }
            try {
                $sent += $this->telegram->send($chat, $text) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}

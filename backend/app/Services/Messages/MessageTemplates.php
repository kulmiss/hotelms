<?php

namespace App\Services\Messages;

use App\Models\MessageTemplate;

/** The texts of the guest messages: the hotel's own (Settings → Guest messages) or the defaults (config/guest_messages.php). */
class MessageTemplates
{
    public const LANGUAGES = ['en', 'am'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(config('guest_messages.messages'));
    }

    /** @return array{subject: string, body: string, custom: bool} */
    public function get(string $key, string $lang): array
    {
        $lang = in_array($lang, self::LANGUAGES, true) ? $lang : 'en';
        $row = MessageTemplate::where('key', $key)->where('language', $lang)->first();
        if ($row) {
            return ['subject' => $row->subject, 'body' => $row->body, 'custom' => true];
        }
        $d = config("guest_messages.messages.{$key}.{$lang}") ?? config("guest_messages.messages.{$key}.en");

        return ['subject' => $d['subject'], 'body' => $d['body'], 'custom' => false];
    }

    /**
     * Fill in the {placeholders}. A line with a placeholder that has no value is left out, so a hotel without
     * Wi-Fi set gets no "Wi-Fi:" line. Unknown {words} stay as typed.
     *
     * @param  array<string, string|null>  $vars
     * @return array{subject: string, body: string}
     */
    public function render(string $key, string $lang, array $vars): array
    {
        $t = $this->get($key, $lang);
        $fill = function (string $text, bool $dropEmpty) use ($vars): string {
            $lines = [];
            foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
                $empty = false;
                $line = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars, &$empty) {
                    if (! array_key_exists($m[1], $vars)) {
                        return $m[0];
                    }
                    $v = trim((string) $vars[$m[1]]);
                    $empty = $empty || $v === '';

                    return $v;
                }, $line);
                if (! ($dropEmpty && $empty)) {
                    $lines[] = $line;
                }
            }

            return trim(implode("\n", $lines));
        };

        return ['subject' => $fill($t['subject'], false), 'body' => $fill($t['body'], true)];
    }
}

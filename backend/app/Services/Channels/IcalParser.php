<?php

namespace App\Services\Channels;

use Illuminate\Support\Carbon;

/**
 * Reads the busy dates of an iCal calendar (RFC 5545) as sent by Airbnb, Booking.com, VRBO and others:
 * only VEVENT start/end, UID and SUMMARY. Folded lines are joined; cancelled events are left out.
 */
class IcalParser
{
    public const MAX_EVENTS = 3000;

    /** @return list<array{uid: string, starts_on: string, ends_on: string, summary: ?string}> */
    public static function events(string $ics, string $timezone): array
    {
        // Unfold: a line that starts with a space or tab continues the one before.
        $text = preg_replace("/\r\n|\r/", "\n", $ics);
        $text = preg_replace("/\n[ \t]/", '', (string) $text);
        if (! str_contains((string) $text, 'BEGIN:VCALENDAR')) {
            throw new \InvalidArgumentException('This link does not return a calendar.');
        }

        $out = [];
        $event = null;
        foreach (explode("\n", (string) $text) as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $event = [];

                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($event !== null && ($row = self::row($event, $timezone))) {
                    $out[] = $row;
                    if (count($out) >= self::MAX_EVENTS) {
                        break;
                    }
                }
                $event = null;

                continue;
            }
            if ($event === null || ! str_contains($line, ':')) {
                continue;
            }
            [$head, $value] = explode(':', $line, 2);
            $parts = explode(';', $head);
            $name = strtoupper(array_shift($parts));
            $params = [];
            foreach ($parts as $p) {
                [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
                $params[strtoupper($k)] = trim($v, '"');
            }
            $event[$name] = ['value' => trim($value), 'params' => $params];
        }

        return $out;
    }

    /** @param array<string, array{value: string, params: array<string, string>}> $e */
    private static function row(array $e, string $timezone): ?array
    {
        if (! isset($e['DTSTART']) || strtoupper($e['STATUS']['value'] ?? '') === 'CANCELLED') {
            return null;
        }
        $start = self::day($e['DTSTART'], $timezone);
        if (! $start) {
            return null;
        }
        $end = isset($e['DTEND']) ? self::day($e['DTEND'], $timezone) : null;
        if (! $end || $end <= $start) {
            $end = Carbon::parse($start)->addDay()->toDateString(); // one night
        }
        $uid = $e['UID']['value'] ?? ('no-uid-'.$start.'-'.$end);

        return [
            'uid' => mb_substr($uid, 0, 191),
            'starts_on' => $start,
            'ends_on' => $end,
            'summary' => isset($e['SUMMARY']) ? mb_substr(self::text($e['SUMMARY']['value']), 0, 120) : null,
        ];
    }

    /** @param array{value: string, params: array<string, string>} $prop a date (20261120) or a time (20261120T140000[Z]) → "2026-11-20" in the hotel's zone */
    private static function day(array $prop, string $timezone): ?string
    {
        $v = $prop['value'];
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $v, $m)) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }
            $zone = $m[7] === 'Z' ? 'UTC' : ($prop['params']['TZID'] ?? $timezone);
            try {
                $t = Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], $zone);
            } catch (\Throwable) {
                $t = Carbon::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], $timezone);
            }

            return $t->setTimezone($timezone)->toDateString();
        }

        return null;
    }

    private static function text(string $v): string
    {
        return trim(str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], [' ', ' ', ',', ';', '\\'], $v));
    }
}

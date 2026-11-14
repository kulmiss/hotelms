<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Friendly "you reached your plan's limit" texts with how to upgrade (no online billing yet: WhatsApp or email,
 * both editable in Super Admin → Settings). Nothing is ever deleted when a hotel is over a limit.
 */
class PlanLimits
{
    /** "… Upgrade: WhatsApp +251… or info@kulmiss.com." */
    public static function message(string $text): string
    {
        $ways = array_values(array_filter([
            ($w = trim((string) PlatformSetting::read('whatsapp'))) !== '' ? "WhatsApp {$w}" : null,
            ($e = trim((string) PlatformSetting::read('support_email'))) !== '' ? $e : null,
        ]));

        return rtrim($text, '. ').'. To upgrade your plan, contact us'.($ways ? ': '.implode(' or ', $ways) : ' (Help → Ask us)').'.';
    }
}

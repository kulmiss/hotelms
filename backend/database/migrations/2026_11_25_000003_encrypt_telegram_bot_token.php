<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** SECURITY_REPORT M6: the Telegram bot token is stored encrypted (APP_KEY), like the mail password. */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('platform_settings')->where('key', 'telegram_bot_token')->first();
        $value = $row ? json_decode((string) $row->value, true) : null;
        if (! is_string($value) || $value === '') {
            return;
        }
        try {
            Crypt::decryptString($value); // already encrypted

            return;
        } catch (DecryptException) {
            DB::table('platform_settings')->where('key', 'telegram_bot_token')->update(['value' => json_encode(Crypt::encryptString($value))]);
        }
    }

    public function down(): void
    {
        $row = DB::table('platform_settings')->where('key', 'telegram_bot_token')->first();
        $value = $row ? json_decode((string) $row->value, true) : null;
        if (is_string($value) && $value !== '') {
            try {
                DB::table('platform_settings')->where('key', 'telegram_bot_token')->update(['value' => json_encode(Crypt::decryptString($value))]);
            } catch (DecryptException) {
                // plain already
            }
        }
    }
};

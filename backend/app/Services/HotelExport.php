<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\Hotel;
use App\Support\Spreadsheet;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * "Download all our data" (decisions.md W7): one ZIP with a CSV per table, only this hotel's rows.
 * Secrets (passwords, codes, PIN hashes, device tokens) are never included; guest IDs are decrypted.
 */
class HotelExport
{
    /** Not exported at all. */
    private const SKIP = ['user_devices', 'telegram_links', 'impersonation_logs', 'imports', 'number_sequences'];

    /** Columns never exported. */
    private const SECRET = ['password', 'remember_token', 'attendance_pin', 'two_factor_secret', 'two_factor_recovery_codes', 'id_number_hash', 'token_hash'];

    /** @return string path of the ZIP (delete it after sending) */
    public function zip(Hotel $hotel): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is missing on this server.');
        }
        $path = tempnam(sys_get_temp_dir(), 'hms-export-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $tables = collect(DB::select('SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?', ['hotel_id']))
            ->pluck('t')->unique()->reject(fn ($t) => in_array($t, self::SKIP, true))->sort()->values();

        foreach ($tables as $table) {
            if ($table === 'guests') {
                $zip->addFromString('guests.csv', $this->guests($hotel));

                continue;
            }
            $rows = DB::table($table)->where('hotel_id', $hotel->id)->orderBy('id')->get()->map(fn ($r) => array_diff_key((array) $r, array_flip(self::SECRET)));
            $headers = $rows->isNotEmpty() ? array_keys($rows->first()) : array_values(array_diff(array_column(DB::select("SHOW COLUMNS FROM `{$table}`"), 'Field'), self::SECRET));
            $zip->addFromString("{$table}.csv", Spreadsheet::csv($headers, $rows->map(fn ($r) => array_values($r))->all()));
        }
        // Child tables without their own hotel_id.
        $children = [
            'reservation_room_rates' => ['reservation_room_id', 'reservation_rooms'],
            'invoice_item_taxes' => ['invoice_item_id', 'invoice_items'],
            'cashier_session_balances' => ['cashier_session_id', 'cashier_sessions'],
        ];
        foreach ($children as $table => [$fk, $parent]) {
            $rows = DB::table($table)->whereIn($fk, DB::table($parent)->where('hotel_id', $hotel->id)->select('id'))->orderBy('id')->get()->map(fn ($r) => (array) $r);
            $headers = $rows->isNotEmpty() ? array_keys($rows->first()) : array_column(DB::select("SHOW COLUMNS FROM `{$table}`"), 'Field');
            $zip->addFromString("{$table}.csv", Spreadsheet::csv($headers, $rows->map(fn ($r) => array_values($r))->all()));
        }
        $zip->addFromString('README.txt', "All data of {$hotel->name}, exported ".now()->toIso8601String()." (times in UTC).\nOne CSV file per table. Money is in the currency of each row.\n");
        $zip->close();

        return $path;
    }

    private function guests(Hotel $hotel): string
    {
        $headers = ['id', 'first_name', 'last_name', 'phone', 'email', 'nationality', 'id_type', 'id_number', 'gender', 'date_of_birth', 'notes', 'is_blacklisted', 'blacklist_reason', 'guest_type_id', 'created_at', 'deleted_at'];
        $rows = app(TenantContext::class)->runAs($hotel, fn () => Guest::withTrashed()->orderBy('id')->get()
            ->map(fn (Guest $g) => array_map(fn ($k) => $g->getAttribute($k) instanceof \DateTimeInterface ? $g->getAttribute($k)->format('Y-m-d H:i:s') : $g->getAttribute($k), $headers))->all());

        return Spreadsheet::csv($headers, $rows);
    }
}

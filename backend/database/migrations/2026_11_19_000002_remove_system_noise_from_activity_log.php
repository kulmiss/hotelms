<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Smart alerts and report schedules wrote "updated" entries every few minutes only because
 * the system noted when it last checked. Those entries are removed (they were not changes by people).
 */
return new class extends Migration
{
    public function up(): void
    {
        $noise = ['last_checked_at', 'next_run_at', 'last_run_at', 'last_sent_at', 'last_error', 'updated_at'];
        DB::table('activity_logs')->where('event', 'updated')->whereIn('subject_type', ['AlertRule', 'ReportSchedule'])
            ->orderBy('id')->chunkById(1000, function ($rows) use ($noise) {
                $ids = collect($rows)->filter(function ($row) use ($noise) {
                    $new = array_keys((array) (json_decode((string) $row->properties, true)['new'] ?? []));

                    return $new !== [] && array_diff($new, $noise) === [];
                })->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table('activity_logs')->whereIn('id', $ids)->delete();
                }
            });
    }

    public function down(): void {}
};

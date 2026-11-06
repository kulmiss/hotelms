<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\Backups;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Super Admin → System health (area 8): database, cache, scheduler (cron), background jobs,
 * backups and the latest errors. Works on shared hosting (no shell commands).
 */
class SystemController extends Controller
{
    public const HEARTBEAT = 'hotelms:scheduler_heartbeat';

    public function __invoke(Backups $backups): JsonResponse
    {
        $checks = [];

        $t = microtime(true);
        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000)];
        } catch (Throwable $e) {
            $checks['database'] = ['ok' => false, 'problem' => 'The database does not answer.'];
        }

        try {
            $probe = bin2hex(random_bytes(4)); // a string: every cache driver gives it back the same
            Cache::put('hotelms:health_probe', $probe, 10);
            $checks['cache'] = ['ok' => Cache::get('hotelms:health_probe') === $probe, 'driver' => config('cache.default')];
        } catch (Throwable) {
            $checks['cache'] = ['ok' => false, 'driver' => config('cache.default'), 'problem' => 'The cache does not work.'];
        }

        $beat = Cache::get(self::HEARTBEAT);
        $last = $beat ? Carbon::parse($beat) : null;
        $checks['scheduler'] = [
            'ok' => $last !== null && $last->gt(now()->subMinutes(5)),
            'last_run' => $last?->toIso8601String(),
            'problem' => $last === null ? 'The cron job has never run. Add the cron line from docs/hosting.md.' : ($last->lt(now()->subMinutes(5)) ? 'The cron job stopped running.' : null),
        ];

        $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->latest('id')->limit(10)->get(['id', 'queue', 'failed_at', 'exception']) : collect();
        $checks['jobs'] = [
            'ok' => $failed->isEmpty() && (! $oldest || (int) $oldest > now()->subMinutes(10)->timestamp),
            'waiting' => $jobs,
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'recent_failures' => $failed->map(fn ($f) => ['id' => $f->id, 'queue' => $f->queue, 'at' => Carbon::parse($f->failed_at)->toIso8601String(), 'error' => mb_substr(strtok((string) $f->exception, "\n") ?: '', 0, 300)])->values(),
        ];

        $checks['backups'] = $this->backups($backups);
        $disk = @disk_free_space(storage_path());
        $checks['disk'] = ['ok' => $disk === false || $disk > 500 * 1024 * 1024, 'free_mb' => $disk === false ? null : (int) ($disk / 1024 / 1024)];

        return response()->json(['data' => [
            'checks' => $checks,
            'errors' => $this->errors(),
            'versions' => ['app' => config('app.version', '1.0.0'), 'php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => $this->dbVersion()],
            'checked_at' => now()->toIso8601String(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function backups(Backups $backups): array
    {
        $out = ['ok' => false];
        foreach (['daily', 'small'] as $kind) {
            $files = glob($backups->folder()."/{$kind}/*.sql.gz") ?: [];
            rsort($files);
            $newest = $files[0] ?? null;
            $out[$kind] = $newest ? ['file' => basename($newest), 'at' => Carbon::createFromTimestamp((int) filemtime($newest))->toIso8601String(), 'size_kb' => (int) (filesize($newest) / 1024), 'count' => count($files)] : null;
        }
        $out['ok'] = $out['daily'] !== null && Carbon::parse($out['daily']['at'])->gt(now()->subHours(26));
        $out['problem'] = $out['ok'] ? null : ($out['daily'] === null ? 'No daily backup yet.' : 'The newest daily backup is more than a day old.');

        return $out;
    }

    /** The last 20 errors from the log file (message only, never the full trace). @return list<array{at: string, message: string}> */
    private function errors(): array
    {
        $file = storage_path('logs/laravel.log');
        if (! is_readable($file)) {
            return [];
        }
        $size = filesize($file);
        $fh = fopen($file, 'rb');
        if (! $fh) {
            return [];
        }
        fseek($fh, max(0, $size - 512 * 1024)); // only the end of a big file
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:]+)[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,300})/m', $text, $m, PREG_SET_ORDER);

        return array_reverse(array_map(fn ($x) => ['at' => $x[1], 'level' => strtolower($x[2]), 'message' => $x[3]], array_slice($m, -20)));
    }

    private function dbVersion(): ?string
    {
        try {
            return DB::getDriverName() === 'mysql' ? (string) DB::scalar('select version()') : DB::getDriverName();
        } catch (Throwable) {
            return null;
        }
    }
}

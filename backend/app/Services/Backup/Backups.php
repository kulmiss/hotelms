<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Database backups in plain PHP (decisions.md W7), so they work on shared hosting without mysqldump:
 * - daily: everything; hourly ("small"): everything except logs, sessions and cache
 * - a copy goes to a second folder (BACKUP_COPY_PATH, e.g. a mounted disk at another Ethiopian location)
 * - kept 30 days (daily) and 48 hours (hourly)
 * - check() opens the newest file and confirms every table is in it (run monthly; a failure emails the team).
 */
class Backups
{
    /** Never in any backup: they rebuild themselves. */
    private const SKIP = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /** Left out of the hourly (small) backup. */
    private const SKIP_SMALL = ['activity_logs', 'impersonation_logs', 'alert_events'];

    public function folder(): string
    {
        return (string) config('hotelms.backups.path');
    }

    /** @return array{file: string, tables: int, rows: int, bytes: int, copied: bool} */
    public function make(string $kind = 'daily'): array
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Backups need MySQL.');
        }
        $dir = $this->folder().'/'.$kind;
        File::ensureDirectoryExists($dir, 0700);
        $name = now()->format('Y-m-d_His').'.sql.gz';
        $path = "{$dir}/{$name}";
        $tmp = "{$path}.part";

        $gz = gzopen($tmp, 'wb6');
        if (! $gz) {
            throw new RuntimeException("Cannot write {$tmp}");
        }
        $pdo = DB::connection()->getPdo();
        $tables = $this->tables($kind);
        $rows = 0;
        gzwrite($gz, "-- HotelMS backup ({$kind}) ".now()->toIso8601String()."\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        foreach ($tables as $table) {
            $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            gzwrite($gz, "\n-- table {$table}\nDROP TABLE IF EXISTS `{$table}`;\n".array_values($create)[1].";\n");
            $columns = null;
            $batch = [];
            foreach (DB::table($table)->orderByRaw('1')->cursor() as $row) {
                $row = (array) $row;
                $columns ??= '`'.implode('`, `', array_keys($row)).'`';
                $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
                $rows++;
                if (count($batch) === 200) {
                    gzwrite($gz, "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
        }
        gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n");
        gzclose($gz);
        rename($tmp, $path); // only complete files get the real name
        @chmod($path, 0600);

        $copied = false;
        if ($copy = config('hotelms.backups.copy_path')) {
            File::ensureDirectoryExists("{$copy}/{$kind}", 0700);
            $copied = File::copy($path, "{$copy}/{$kind}/{$name}");
        }
        $this->prune($kind);
        if ($kind === 'daily') {
            $this->syncFiles();
        }

        return ['file' => $path, 'tables' => count($tables), 'rows' => $rows, 'bytes' => (int) filesize($path), 'copied' => $copied];
    }

    /** Uploaded files kept by the hotels (payment proofs, encrypted ID photos). */
    public const FILE_FOLDERS = ['payment-proofs', 'guest-ids'];

    /**
     * Daily: the uploaded files are mirrored next to the database backups (and to the second copy),
     * new files copied, deleted ones removed (so the hotels' "delete after" rules hold in backups too).
     *
     * @return array{copied: int, removed: int}
     */
    public function syncFiles(): array
    {
        $copied = 0;
        $removed = 0;
        $targets = array_filter([$this->folder().'/files', ($c = config('hotelms.backups.copy_path')) ? "{$c}/files" : null]);
        foreach (self::FILE_FOLDERS as $folder) {
            $source = storage_path("app/private/{$folder}");
            $have = File::isDirectory($source) ? collect(File::allFiles($source))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => $f->getPathname()]) : collect();
            foreach ($targets as $target) {
                $dir = "{$target}/{$folder}";
                File::ensureDirectoryExists($dir, 0700);
                foreach ($have as $relative => $path) {
                    $to = "{$dir}/{$relative}";
                    if (! File::exists($to)) {
                        File::ensureDirectoryExists(dirname($to), 0700);
                        File::copy($path, $to);
                        $copied++;
                    }
                }
                foreach (File::allFiles($dir) as $old) {
                    if (! $have->has($old->getRelativePathname())) {
                        File::delete($old->getPathname());
                        $removed++;
                    }
                }
            }
        }

        return ['copied' => $copied, 'removed' => $removed];
    }

    /** Opens the newest backup and checks it is complete. @return array{ok: bool, file: ?string, problem: ?string, tables: int} */
    public function check(string $kind = 'daily'): array
    {
        $files = glob($this->folder()."/{$kind}/*.sql.gz") ?: [];
        sort($files);
        $file = end($files) ?: null;
        if (! $file) {
            return ['ok' => false, 'file' => null, 'problem' => 'No backup found.', 'tables' => 0];
        }
        $age = Carbon::createFromTimestamp((int) filemtime($file));
        $limit = $kind === 'daily' ? 26 : 2;
        if ($age->lt(now()->subHours($limit))) {
            return ['ok' => false, 'file' => $file, 'problem' => "The newest backup is older than {$limit} hours.", 'tables' => 0];
        }

        $gz = gzopen($file, 'rb');
        $found = [];
        $end = false;
        while ($gz && ! gzeof($gz)) {
            $line = (string) gzgets($gz, 1 << 20);
            if (str_starts_with($line, '-- table ')) {
                $found[] = trim(substr($line, 9));
            }
            $end = $end || str_starts_with($line, '-- end of backup');
        }
        if ($gz) {
            gzclose($gz);
        }
        $missing = array_diff($this->tables($kind), $found);
        $problem = ! $end ? 'The backup file is cut off.' : ($missing ? 'Missing tables: '.implode(', ', $missing) : null);

        return ['ok' => $problem === null, 'file' => $file, 'problem' => $problem, 'tables' => count($found)];
    }

    /** Puts a backup back (replaces the current data). */
    public function restore(string $file): int
    {
        $gz = gzopen($file, 'rb');
        if (! $gz) {
            throw new RuntimeException("Cannot open {$file}");
        }
        $pdo = DB::connection()->getPdo();
        $statement = '';
        $count = 0;
        while (! gzeof($gz)) {
            $line = (string) gzgets($gz, 1 << 20);
            if ($statement === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $statement .= $line;
            if (str_ends_with(rtrim($line), ';')) {
                $pdo->exec($statement);
                $statement = '';
                $count++;
            }
        }
        gzclose($gz);

        return $count;
    }

    /** @return list<string> */
    private function tables(string $kind): array
    {
        $skip = $kind === 'hourly' ? array_merge(self::SKIP, self::SKIP_SMALL) : self::SKIP;

        return collect(DB::select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))
            ->map(fn ($r) => array_values((array) $r)[0])
            ->reject(fn ($t) => in_array($t, $skip, true))->values()->all();
    }

    private function prune(string $kind): void
    {
        $keepUntil = $kind === 'daily' ? now()->subDays((int) config('hotelms.backups.keep_days')) : now()->subHours(48);
        foreach (array_filter([$this->folder(), config('hotelms.backups.copy_path')]) as $root) {
            foreach (glob("{$root}/{$kind}/*.sql.gz") ?: [] as $f) {
                if (filemtime($f) < $keepUntil->getTimestamp()) {
                    @unlink($f);
                }
            }
        }
    }
}

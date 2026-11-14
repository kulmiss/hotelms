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
 * - encrypted with BACKUP_KEY (BackupCipher, SECURITY_REPORT H1): files end in .sql.gz.enc. A live server
 *   (APP_ENV=production) refuses to make backups without the key; old unencrypted .sql.gz files can
 *   still be checked and restored.
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
        $key = BackupCipher::key();
        if ($key === null && app()->isProduction()) {
            throw new RuntimeException('BACKUP_KEY is missing, so no backup was made (backups are always encrypted on a live server). Make one with: php artisan hotelms:backup-key');
        }
        $dir = $this->folder().'/'.$kind;
        File::ensureDirectoryExists($dir, 0700);
        $name = now()->format('Y-m-d_His').'.sql.gz'.($key ? BackupCipher::EXT : '');
        $path = "{$dir}/{$name}";
        $tmp = "{$path}.part";

        if ($key) {
            $cipher = BackupCipher::create($tmp, $key);
            $write = fn (string $text) => $cipher->write($text);
            $finish = fn () => $cipher->close();
        } else {
            $gz = gzopen($tmp, 'wb6');
            if (! $gz) {
                throw new RuntimeException("Cannot write {$tmp}");
            }
            $write = fn (string $text) => gzwrite($gz, $text);
            $finish = fn () => gzclose($gz);
        }
        $pdo = DB::connection()->getPdo();
        $tables = $this->tables($kind);
        $rows = 0;
        $write("-- HotelMS backup ({$kind}) ".now()->toIso8601String()."\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        foreach ($tables as $table) {
            $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $write("\n-- table {$table}\nDROP TABLE IF EXISTS `{$table}`;\n".array_values($create)[1].";\n");
            $columns = null;
            $batch = [];
            foreach (DB::table($table)->orderByRaw('1')->cursor() as $row) {
                $row = (array) $row;
                $columns ??= '`'.implode('`, `', array_keys($row)).'`';
                $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
                $rows++;
                if (count($batch) === 200) {
                    $write("INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $write("INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
            }
        }
        $write("\nSET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n");
        $finish();
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
        $key = BackupCipher::key();
        $ext = $key ? BackupCipher::EXT : ''; // encrypted copies get .enc (plain copies from before are replaced)
        foreach (self::FILE_FOLDERS as $folder) {
            $source = storage_path("app/private/{$folder}");
            $have = File::isDirectory($source) ? collect(File::allFiles($source))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => $f->getPathname()]) : collect();
            foreach ($targets as $target) {
                $dir = "{$target}/{$folder}";
                File::ensureDirectoryExists($dir, 0700);
                foreach ($have as $relative => $path) {
                    $to = "{$dir}/{$relative}{$ext}";
                    if (! File::exists($to)) {
                        File::ensureDirectoryExists(dirname($to), 0700);
                        $key ? BackupCipher::encryptFile($path, $to, $key) : File::copy($path, $to);
                        $copied++;
                    }
                }
                foreach (File::allFiles($dir) as $old) {
                    $name = $old->getRelativePathname();
                    $source = $ext !== '' && str_ends_with($name, $ext) ? substr($name, 0, -strlen($ext)) : ($ext === '' ? $name : null);
                    if ($source === null || ! $have->has($source)) {
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
        $files = $this->files($kind);
        $file = end($files) ?: null;
        if (! $file) {
            return ['ok' => false, 'file' => null, 'problem' => 'No backup found.', 'tables' => 0];
        }
        $age = Carbon::createFromTimestamp((int) filemtime($file));
        $limit = $kind === 'daily' ? 26 : 2;
        if ($age->lt(now()->subHours($limit))) {
            return ['ok' => false, 'file' => $file, 'problem' => "The newest backup is older than {$limit} hours.", 'tables' => 0];
        }

        $found = [];
        $end = false;
        try {
            foreach ($this->lines($file) as $line) {
                if (str_starts_with($line, '-- table ')) {
                    $found[] = trim(substr($line, 9));
                }
                $end = $end || str_starts_with($line, '-- end of backup');
            }
        } catch (RuntimeException $e) {
            return ['ok' => false, 'file' => $file, 'problem' => $e->getMessage(), 'tables' => count($found)];
        }
        $missing = array_diff($this->tables($kind), $found);
        $problem = ! $end ? 'The backup file is cut off.' : ($missing ? 'Missing tables: '.implode(', ', $missing) : null);

        return ['ok' => $problem === null, 'file' => $file, 'problem' => $problem, 'tables' => count($found)];
    }

    /** Puts a backup back (replaces the current data). */
    public function restore(string $file): int
    {
        if (! is_readable($file)) {
            throw new RuntimeException("Cannot open {$file}");
        }
        $pdo = DB::connection()->getPdo();
        $statement = '';
        $count = 0;
        foreach ($this->lines($file) as $line) {
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

        return $count;
    }

    /**
     * The backups of one kind, oldest first: encrypted (.sql.gz.enc) and older plain ones (.sql.gz).
     * The names start with the date, so sorting by name sorts by time.
     *
     * @return list<string>
     */
    public function files(string $kind): array
    {
        $files = array_merge(glob($this->folder()."/{$kind}/*.sql.gz") ?: [], glob($this->folder()."/{$kind}/*.sql.gz".BackupCipher::EXT) ?: []);
        usort($files, fn ($a, $b) => strcmp(basename($a), basename($b)));

        return $files;
    }

    /**
     * A backup's text line by line: decrypted with BACKUP_KEY, or read as plain gzip (older backups).
     *
     * @return \Generator<int, string>
     */
    private function lines(string $file): \Generator
    {
        if (BackupCipher::isEncrypted($file)) {
            $key = BackupCipher::key() ?? throw new RuntimeException('This backup is encrypted: set BACKUP_KEY in .env to open it.');
            yield from BackupCipher::lines($file, $key);

            return;
        }
        $gz = gzopen($file, 'rb');
        if (! $gz) {
            throw new RuntimeException("Cannot open {$file}");
        }
        try {
            while (! gzeof($gz)) {
                yield (string) gzgets($gz, 1 << 20);
            }
        } finally {
            gzclose($gz);
        }
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
            foreach (array_merge(glob("{$root}/{$kind}/*.sql.gz") ?: [], glob("{$root}/{$kind}/*.sql.gz".BackupCipher::EXT) ?: []) as $f) {
                if (filemtime($f) < $keepUntil->getTimestamp()) {
                    @unlink($f);
                }
            }
        }
    }
}

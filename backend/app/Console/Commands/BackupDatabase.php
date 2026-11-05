<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Backup\Backups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'hotelms:backup {--small : hourly backup without logs} {--check : open the newest daily backup and confirm it is complete} {--restore= : put this backup file back (replaces ALL data)} {--force}';

    protected $description = 'Back up the database (daily / hourly), check the newest backup, or restore one';

    public function handle(Backups $backups): int
    {
        try {
            if ($file = $this->option('restore')) {
                if (! $this->option('force') && ! $this->confirm("Replace ALL current data with {$file}?")) {
                    return self::FAILURE;
                }
                $this->info($backups->restore($file).' statements restored.');

                return self::SUCCESS;
            }
            if ($this->option('check')) {
                $r = $backups->check('daily');
                if (! $r['ok']) {
                    $this->tellTeam('Backup check failed: '.$r['problem']);
                    $this->error($r['problem']);

                    return self::FAILURE;
                }
                $this->info("OK: {$r['tables']} tables in {$r['file']}");

                return self::SUCCESS;
            }
            $r = $backups->make($this->option('small') ? 'hourly' : 'daily');
            $this->info("Backup {$r['file']}: {$r['tables']} tables, {$r['rows']} rows, ".round($r['bytes'] / 1024).' KB'.($r['copied'] ? ', copied' : ''));

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->tellTeam('Backup failed: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** Emails the HotelMS owners (and the support address) when something is wrong with backups. */
    private function tellTeam(string $message): void
    {
        $to = User::where('is_super_admin', true)->where('status', 'active')->pluck('email')->push(PlatformSetting::read('support_email'))->filter()->unique();
        foreach ($to as $email) {
            try {
                Mail::raw($message."\n\nServer: ".config('app.url'), fn ($m) => $m->to($email)->subject('HotelMS backup problem'));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}

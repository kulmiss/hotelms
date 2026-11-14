<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupCipher;
use Illuminate\Console\Command;

/** Prints a new key for encrypted backups (SECURITY_REPORT H1). It is shown once and never saved by HotelMS. */
class MakeBackupKey extends Command
{
    protected $signature = 'hotelms:backup-key';

    protected $description = 'Make a new BACKUP_KEY for encrypted backups (put it in .env and in your password manager)';

    public function handle(): int
    {
        if (config('hotelms.backups.key')) {
            $this->warn('BACKUP_KEY is already set. Backups made with the old key need the OLD key to open them: keep it.');
        }
        $this->line('');
        $this->line('BACKUP_KEY='.BackupCipher::newKey());
        $this->line('');
        $this->info('1. Put this line in .env (replace an empty BACKUP_KEY= line).');
        $this->info('2. Save the same line in your password manager, NOT on the server only.');
        $this->info('3. Run php artisan config:cache (if you use it), then php artisan hotelms:backup and --check.');
        $this->info('Without this key the backups cannot be opened, also by HotelMS.');

        return self::SUCCESS;
    }
}

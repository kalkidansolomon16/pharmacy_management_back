<?php

namespace App\Console\Commands;

use App\Services\Platform\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackupDatabase extends Command
{
    protected $signature = 'system:backup {--decrypt= : Decrypt a backup file to plain SQL next to it}';

    protected $description = 'Create an encrypted database backup (or decrypt one for restore)';

    public function handle(BackupService $backups): int
    {
        if ($file = $this->option('decrypt')) {
            $target = str_replace('.sql.gz.enc', '.sql', $backups->path($file));
            File::put($target, $backups->decrypt($file));
            $this->info("Decrypted to {$target}");

            return self::SUCCESS;
        }

        $result = $backups->run();
        $this->info("Backup {$result['file']} (".number_format($result['size'] / 1024, 1).' KB) created.');

        return self::SUCCESS;
    }
}

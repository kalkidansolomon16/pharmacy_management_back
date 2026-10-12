<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Daily encrypted database backups (gzip + AES-256 with the application key), keeping the last N.
 * Restore: decrypt with `php artisan system:backup --decrypt=<file>` then import the SQL.
 */
class BackupService
{
    public function run(): array
    {
        $dir = config('medlink.backup.disk_path');
        File::ensureDirectoryExists($dir);

        $sql = $this->dump();
        $name = 'medlink-'.now()->format('Y-m-d_His').'.sql.gz.enc';
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        File::put($path, Crypt::encryptString(base64_encode(gzencode($sql, 9))));

        $this->prune($dir);

        return ['file' => $name, 'size' => File::size($path), 'created_at' => now()->toIso8601String()];
    }

    public function list(): array
    {
        $dir = config('medlink.backup.disk_path');
        if (! File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.enc'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->map(fn ($f) => ['file' => $f->getFilename(), 'size' => $f->getSize(), 'created_at' => date(DATE_ATOM, $f->getMTime())])
            ->values()
            ->all();
    }

    public function path(string $file): string
    {
        $path = config('medlink.backup.disk_path').DIRECTORY_SEPARATOR.basename($file);
        if (! File::exists($path)) {
            throw new RuntimeException('Backup not found.');
        }

        return $path;
    }

    public function decrypt(string $file): string
    {
        return gzdecode(base64_decode(Crypt::decryptString(File::get($this->path($file)))));
    }

    private function dump(): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if ($config['driver'] === 'sqlite') {
            return File::get($config['database']);
        }

        if (in_array($config['driver'], ['mysql', 'mariadb'], true)) {
            $process = new Process([
                config('medlink.backup.mysqldump'), '--single-transaction', '--routines', '--no-tablespaces',
                '-h', $config['host'], '-P', (string) $config['port'], '-u', $config['username'], $config['database'],
            ], null, $config['password'] ? ['MYSQL_PWD' => $config['password']] : null, null, 600);
            $process->run();
            if ($process->isSuccessful()) {
                return $process->getOutput();
            }

            return $this->phpDump(); // mysqldump not installed / not on PATH
        }

        throw new RuntimeException("Backups are not supported for the {$config['driver']} driver.");
    }

    /** Fallback: portable INSERT-based dump through PDO. */
    private function phpDump(): string
    {
        $out = "SET FOREIGN_KEY_CHECKS=0;\n";
        $pdo = DB::connection()->getPdo();
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n".array_values($create)[1].";\n";
            foreach (DB::table($table)->cursor() as $record) {
                $values = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), (array) $record);
                $out .= "INSERT INTO `{$table}` VALUES (".implode(',', $values).");\n";
            }
        }

        return $out."SET FOREIGN_KEY_CHECKS=1;\n";
    }

    private function prune(string $dir): void
    {
        collect(File::files($dir))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.enc'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->slice(config('medlink.backup.keep'))
            ->each(fn ($f) => File::delete($f->getPathname()));
    }
}

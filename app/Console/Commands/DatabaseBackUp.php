<?php

namespace App\Console\Commands;

use App\Services\Updater\DatabaseBackupService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DatabaseBackUp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'database:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    /**
     * Security fix (Build N1 / audit C-04): backups now live under
     * storage/app/backups (NOT storage/app/public/backup). Anything under
     * storage/app/public is what `php artisan storage:link` exposes at
     * public/storage/... — a raw SQL dump with a predictable filename
     * (backup-YYYY-MM-DD.sql) sitting there was web-downloadable on any
     * install that had run storage:link. storage/app/backups is never
     * inside the public disk, so it cannot become web-accessible this way.
     */
    public const BACKUP_DIR_RELATIVE = 'app/backups';

    /** Legacy (pre-fix) location, kept only to migrate old backups out of the public path. */
    private const LEGACY_BACKUP_DIR_RELATIVE = 'app/public/backup';

    public static function backupDir(): string
    {
        return storage_path().'/'.self::BACKUP_DIR_RELATIVE;
    }

    public function handle()
    {
        $backupDir = self::backupDir();
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        // One-time migration: if backups still exist at the old, publicly
        // reachable location, move them into the new private directory
        // instead of leaving them exposed.
        $this->migrateLegacyBackups($backupDir);

        $filename = 'backup-'.Carbon::now()->format('Y-m-d').'_'.Carbon::now()->format('His').'.sql';
        $outputPath = $backupDir.'/'.$filename;

        // Shared hosting often disables exec()/escapeshellarg() through
        // disable_functions, which removes them entirely (function_exists()
        // then returns false). Only take the mysqldump route when both are
        // really callable; otherwise dump with pure PHP.
        $binaryError = null;
        $dumpOk = false;
        if (function_exists('exec') && function_exists('escapeshellarg')) {
            $binaryError = $this->dumpWithMysqldump($outputPath);
            $dumpOk = ($binaryError === null);
            if (!$dumpOk) {
                @unlink($outputPath);
            }
        }

        if (!$dumpOk) {
            try {
                $this->dumpWithPhp($outputPath);
                $dumpOk = true;
            } catch (\Throwable $e) {
                @unlink($outputPath);
                $err = 'PHP database dump failed: '.$e->getMessage();
                if ($binaryError !== null) {
                    $err = $binaryError."\n".$err;
                }
                $this->line('ERROR_DETAILS: '.$err);

                return 1;
            }
        }

        // Security fix (Build N1 / audit C-04): old backups are pruned only
        // AFTER a new backup has been verified non-empty on disk, and only
        // this new file is exempt from pruning. Previously every existing
        // backup was deleted BEFORE the new dump was even attempted, so a
        // failed run left zero recovery points. We also now keep the most
        // recent N backups instead of leaving only one.
        if ($dumpOk && file_exists($outputPath) && filesize($outputPath) > 0) {
            $this->pruneOldBackups($backupDir, $outputPath, 14);

            return 0;
        }

        @unlink($outputPath);
        $this->line('ERROR_DETAILS: backup file was not written or is empty.');

        return 1;
    }

    /** Move any backups found at the old public path into the new private one. */
    private function migrateLegacyBackups(string $newDir): void
    {
        $legacyDir = storage_path().'/'.self::LEGACY_BACKUP_DIR_RELATIVE;
        if (!is_dir($legacyDir)) {
            return;
        }

        foreach (glob($legacyDir.'/*.sql') ?: [] as $legacyFile) {
            $dest = $newDir.'/'.basename($legacyFile);
            if (!file_exists($dest)) {
                @rename($legacyFile, $dest);
            } else {
                @unlink($legacyFile);
            }
        }
    }

    /** Keep only the most recent $keep backups; always keeps $justWritten. */
    private function pruneOldBackups(string $backupDir, string $justWritten, int $keep): void
    {
        $files = glob($backupDir.'/*.sql') ?: [];
        // Newest first.
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        foreach (array_slice($files, $keep) as $old) {
            if ($old !== $justWritten) {
                @unlink($old);
            }
        }
    }

    /**
     * Dump through the mysqldump binary.
     * Returns null on success, or an error description on failure.
     */
    private function dumpWithMysqldump(string $outputPath): ?string
    {
        // env() returns null when config is cached, so read credentials from
        // config() and fall back to plain "mysqldump" when DUMP_PATH is unset.
        $connection = config('database.default');
        $db = config('database.connections.'.$connection);

        $dumpPath = trim((string) env('DUMP_PATH'));
        if ($dumpPath === '') {
            $dumpPath = 'mysqldump';
        } elseif (strpos($dumpPath, ' ') !== false && $dumpPath[0] !== '"') {
            $dumpPath = '"'.$dumpPath.'"';
        }

        $db_user = $db['username'] ?? '';
        $db_pass = $db['password'] ?? '';
        $db_host = $db['host'] ?? '127.0.0.1';
        $db_name = $db['database'] ?? '';

        // Security fix (Build N1 / audit C-04): the DB password is no
        // longer passed as a `--password=...` command-line argument, which
        // is readable by any other local user via `ps`/`/proc`. Instead we
        // write a short-lived, mode-0600 "defaults extra file" that
        // mysqldump reads directly, and delete it immediately after.
        $optionFile = null;
        if ($db_pass !== '') {
            $optionFile = tempnam(sys_get_temp_dir(), 'stocky_db_');
            if ($optionFile === false) {
                return 'could not create a temporary mysqldump options file.';
            }
            file_put_contents($optionFile, "[client]\npassword=".$db_pass."\n");
            @chmod($optionFile, 0600);
        }

        $command = $dumpPath
            .($optionFile !== null ? ' --defaults-extra-file='.escapeshellarg($optionFile) : '')
            .' --user='.escapeshellarg($db_user)
            .' --host='.escapeshellarg($db_host)
            .' '.escapeshellarg($db_name)
            .' > '.escapeshellarg($outputPath);

        $output = [];
        $returnVar = null;
        try {
            @\exec($command.' 2>&1', $output, $returnVar);
        } catch (\Throwable $e) {
            if ($optionFile !== null) {
                @unlink($optionFile);
            }

            return 'mysqldump could not be executed: '.$e->getMessage();
        }

        if ($optionFile !== null) {
            @unlink($optionFile);
        }

        if ($returnVar === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            return null;
        }

        $err = 'mysqldump failed (exit code: '.var_export($returnVar, true).')';
        if (!empty($output)) {
            $err .= "\n".implode("\n", $output);
        } elseif (file_exists($outputPath) && filesize($outputPath) > 0) {
            $err .= "\n".trim((string) @file_get_contents($outputPath));
        }

        return $err;
    }

    /** Pure-PHP dump, used when mysqldump is unavailable or fails. */
    private function dumpWithPhp(string $outputPath): void
    {
        $service = new DatabaseBackupService();
        $cursor = [];
        $neverStop = static function () {
            return false;
        };

        do {
            $service->dumpChunkPhp($outputPath, $cursor, $neverStop);
        } while (empty($cursor['done']));

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException('the dump file was not written.');
        }
    }
}

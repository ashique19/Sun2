<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LoadLegacySqlCommand extends Command
{
    protected $signature = 'legacy:load-sql
                            {--path=storage/sun.sql : Path to the legacy SQL dump}';

    protected $description = 'Create the sun_legacy database and import storage/sun.sql';

    public function handle(): int
    {
        $path = base_path($this->option('path'));

        if (! is_file($path)) {
            $this->error("SQL dump not found at {$path}");

            return self::FAILURE;
        }

        $database = config('database.connections.legacy.database');
        $host = config('database.connections.legacy.host');
        $port = config('database.connections.legacy.port');
        $username = config('database.connections.legacy.username');
        $password = config('database.connections.legacy.password');

        $this->info("Preparing legacy database `{$database}`…");

        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        // Always rebuild legacy DB so re-runs do not hit duplicate-key errors on INSERT dumps.
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$database}`");

        $mysql = $this->findMysqlBinary();

        if ($mysql === null) {
            $this->warn('mysql client not found — loading dump via PDO (slower).');

            return $this->importViaPdo($pdo, $path) ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Importing dump via mysql client…');

        $imported = $this->importWithMysqlClient($mysql, $host, (string) $port, $username, $password, $database, $path);

        if (! $imported) {
            $this->error('mysql import failed.');

            return self::FAILURE;
        }

        $this->info('Legacy SQL import finished.');

        return self::SUCCESS;
    }

    private function importWithMysqlClient(
        string $mysql,
        string $host,
        string $port,
        string $username,
        ?string $password,
        string $database,
        string $path,
    ): bool {
        $args = [
            $mysql,
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            $database,
        ];

        if ($password !== null && $password !== '') {
            $args[] = '--password='.$password;
        }

        $process = proc_open(
            $args,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            return false;
        }

        $input = fopen($path, 'rb');

        if ($input === false) {
            return false;
        }

        stream_copy_to_stream($input, $pipes[0]);
        fclose($input);
        fclose($pipes[0]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && $stderr) {
            $this->error(trim($stderr));
        }

        return $exitCode === 0;
    }

    private function findMysqlBinary(): ?string
    {
        $candidates = [
            'C:\\Program Files\\Herd\\bin\\mysql.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin\\mysql.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe',
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysql.exe',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $which = shell_exec('where mysql 2>NUL');

        if (is_string($which) && trim($which) !== '') {
            return trim(explode("\n", $which)[0]);
        }

        return null;
    }

    private function importViaPdo(\PDO $pdo, string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->error('Unable to open SQL dump.');

            return false;
        }

        $statement = '';
        $executed = 0;

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $statement .= $line;

            if (! str_ends_with(rtrim($line), ';')) {
                continue;
            }

            try {
                $pdo->exec($statement);
                $executed++;
            } catch (\Throwable $e) {
                if (! str_contains($e->getMessage(), 'already exists')) {
                    $this->error($e->getMessage());
                    fclose($handle);

                    return false;
                }
            }

            $statement = '';

            if ($executed % 250 === 0) {
                $this->output->write('.');
            }
        }

        fclose($handle);
        $this->newLine();
        $this->info("Executed {$executed} SQL statements.");

        return true;
    }
}

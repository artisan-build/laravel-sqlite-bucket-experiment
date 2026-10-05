<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Litestream, driven from inside the PHP application.
 *
 * Laravel Cloud's PHP runtime does not hand us the start command, so we cannot
 * use the usual `litestream replicate -exec '<server>'` wrapper that owns the
 * process tree. Instead the first PHP process to come up in a fresh container
 * does the work, under an exclusive file lock, before the framework is allowed
 * to open the database:
 *
 *   1. restore the database from the bucket if it is not on disk,
 *   2. start `litestream replicate` as a detached daemon,
 *   3. run migrations, so their writes land on top of the restored file,
 *   4. drop a readiness marker that every later request short-circuits on.
 *
 * A restore that fails ABORTS the boot. Starting empty on top of a live replica
 * would let Litestream replicate the empty database over the real one, which is
 * the one failure mode that destroys data rather than merely losing a request.
 *
 * Deliberately free of Laravel helpers: this runs before the framework boots.
 */
final class Litestream
{
    /** How long to wait for the daemon's control socket to appear, in seconds. */
    private const float DAEMON_TIMEOUT = 10.0;

    /** @var array<int, string> */
    private static array $log = [];

    /**
     * Prepare the database before anything opens it. Safe to call on every request.
     *
     * @throws RuntimeException when the database cannot be made trustworthy
     */
    public static function boot(): void
    {
        // The directory is created whether or not replication is on: without a
        // bucket the app still has to be able to open a SQLite file somewhere,
        // and on Cloud that somewhere does not exist in a fresh container.
        SqlitePath::ensureDirectory();

        if (! self::enabled() || ! self::onInstance()) {
            return;
        }

        if (self::ready()) {
            return;
        }

        $lock = fopen(self::dir().'/boot.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException('litestream: cannot open boot lock in '.self::dir());
        }

        try {
            flock($lock, LOCK_EX);

            // Another worker may have finished while we queued for the lock.
            if (self::ready()) {
                return;
            }

            self::writeConfig();
            self::restore();
            self::startDaemon();
            self::migrate();

            file_put_contents(self::marker(), (string) time());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Is the bucket attached and the binary shipped?
     *
     * This is the whole of the app's configuration surface: attaching a bucket
     * makes Cloud inject AWS_BUCKET, which turns replication on. Nothing else.
     */
    public static function enabled(): bool
    {
        return self::bucket() !== null && is_executable(self::binary());
    }

    /**
     * Am I the long-lived application instance, or a throwaway Cloud container?
     *
     * Measured on 2026-10-05: Cloud's build container sets LARAVEL_CLOUD_CI and
     * its deploy-command container sets LARAVEL_CLOUD_DEPLOY; the application
     * instance sets neither. Both of those containers have the bucket credentials
     * injected and are gone within a minute, so restoring and replicating from
     * either would put a second writer on the replica for no benefit at all.
     *
     * LITESTREAM_SKIP stays as a manual override for a context we have not met.
     */
    public static function onInstance(): bool
    {
        if (self::env('LITESTREAM_SKIP') !== null) {
            return false;
        }

        return self::env('LARAVEL_CLOUD_CI') === null && self::env('LARAVEL_CLOUD_DEPLOY') === null;
    }

    public static function binary(): string
    {
        return dirname(__DIR__, 2).'/bin/litestream';
    }

    public static function dir(): string
    {
        return SqlitePath::directory();
    }

    public static function configPath(): string
    {
        return self::dir().'/litestream.yml';
    }

    public static function socketPath(): string
    {
        return self::dir().'/litestream.sock';
    }

    public static function logPath(): string
    {
        return self::dir().'/litestream.log';
    }

    public static function pidPath(): string
    {
        return self::dir().'/litestream.pid';
    }

    private static function marker(): string
    {
        return self::dir().'/litestream.ready';
    }

    /** The replica is ready when the marker exists and the daemon is still alive. */
    public static function ready(): bool
    {
        return is_file(self::marker()) && self::daemonPid() !== null;
    }

    public static function daemonPid(): ?int
    {
        if (! is_file(self::pidPath())) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents(self::pidPath()));

        if ($pid <= 0) {
            return null;
        }

        return self::alive($pid) ? $pid : null;
    }

    private static function alive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return is_dir('/proc/'.$pid);
    }

    /**
     * The replica config. No credentials are written: Litestream reads
     * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY from the environment Cloud
     * injects, so the file on disk holds nothing secret.
     */
    public static function config(): string
    {
        $lines = [
            '# Generated by App\Support\Litestream. Credentials come from the environment.',
            'logging:',
            '  level: info',
            '  type: text',
            'socket:',
            '  enabled: true',
            '  path: '.self::socketPath(),
            'dbs:',
            '  - path: '.SqlitePath::resolve(),
            '    replica:',
            '      type: s3',
            '      bucket: '.self::bucket(),
            '      path: '.self::replicaPath(),
            '      region: '.(self::env('AWS_DEFAULT_REGION') ?? self::env('AWS_REGION') ?? 'auto'),
            '      force-path-style: true',
            '      sync-interval: 1s',
        ];

        if (($endpoint = self::endpoint()) !== null) {
            $lines[] = '      endpoint: '.$endpoint;
        }

        return implode("\n", $lines)."\n";
    }

    private static function writeConfig(): void
    {
        file_put_contents(self::configPath(), self::config());
    }

    /**
     * Pull the database down if it is not already here.
     *
     * `-if-db-not-exists` makes an existing file a no-op (a hibernation wake, or
     * a second worker); `-if-replica-exists` makes the very first boot, when the
     * bucket is still empty, a no-op too. Anything else is a hard failure.
     */
    private static function restore(): void
    {
        if (is_file(SqlitePath::resolve())) {
            self::note('restore skipped: database already on disk');

            return;
        }

        $result = self::run([
            self::binary(), 'restore',
            '-config', self::configPath(),
            '-if-db-not-exists',
            '-if-replica-exists',
            SqlitePath::resolve(),
        ]);

        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'litestream restore failed with exit code '.$result['code'].
                ' — refusing to boot on an empty database over a live replica. '.
                trim($result['output'])
            );
        }

        self::note('restore exit 0; database '.(is_file(SqlitePath::resolve()) ? 'restored' : 'absent (empty replica)'));
    }

    /**
     * Start `litestream replicate` detached, so it outlives the PHP worker that
     * launched it. PHP-FPM recycles workers constantly; the daemon must not.
     */
    private static function startDaemon(): void
    {
        if (self::daemonPid() !== null) {
            self::note('daemon already running at pid '.self::daemonPid());

            return;
        }

        @unlink(self::socketPath());

        $command = sprintf(
            'nohup %s replicate -config %s >> %s 2>&1 & echo $!',
            escapeshellarg(self::binary()),
            escapeshellarg(self::configPath()),
            escapeshellarg(self::logPath())
        );

        $pid = (int) trim((string) shell_exec($command));

        if ($pid <= 0) {
            throw new RuntimeException('litestream replicate could not be started');
        }

        file_put_contents(self::pidPath(), (string) $pid);

        $deadline = microtime(true) + self::DAEMON_TIMEOUT;

        while (microtime(true) < $deadline) {
            if (file_exists(self::socketPath())) {
                self::note('daemon up at pid '.$pid);

                return;
            }

            if (! self::alive($pid)) {
                throw new RuntimeException(
                    'litestream replicate exited immediately: '.self::tailLog()
                );
            }

            usleep(100_000);
        }

        throw new RuntimeException(
            'litestream replicate did not open its control socket within '.
            self::DAEMON_TIMEOUT.'s: '.self::tailLog()
        );
    }

    /**
     * Migrate here rather than in a deploy command.
     *
     * A Cloud deploy command runs somewhere this instance's database is not, so
     * migrating there would either do nothing useful or — worse — build a second
     * database. Running it after the restore, under the same lock, guarantees the
     * schema lands on the file that was just pulled from the bucket and that the
     * change is replicated.
     */
    private static function migrate(): void
    {
        $result = self::run([PHP_BINARY, dirname(__DIR__, 2).'/artisan', 'migrate', '--force', '--no-interaction']);

        if ($result['code'] !== 0) {
            throw new RuntimeException('migrate failed after restore: '.trim($result['output']));
        }

        self::note('migrate exit 0');
    }

    /** Ask the running daemon to flush now. Used by the probe endpoints. */
    public static function sync(): array
    {
        return self::run([self::binary(), 'sync', '-config', self::configPath(), SqlitePath::resolve()]);
    }

    public static function status(): array
    {
        return self::run([self::binary(), 'status', '-config', self::configPath()]);
    }

    public static function ltx(): array
    {
        return self::run([self::binary(), 'ltx', '-config', self::configPath(), SqlitePath::resolve()]);
    }

    /** @return array<string, mixed> */
    public static function report(): array
    {
        return [
            'enabled' => self::enabled(),
            'on_instance' => self::onInstance(),
            'binary' => self::binary(),
            'binary_present' => is_executable(self::binary()),
            'version' => is_executable(self::binary()) ? trim(self::run([self::binary(), 'version'])['output']) : null,
            'bucket_attached' => self::bucket() !== null,
            'replica_path' => self::replicaPath(),
            'ready' => self::ready(),
            'daemon_pid' => self::daemonPid(),
            'socket' => file_exists(self::socketPath()),
            'marker_at' => is_file(self::marker()) ? gmdate('c', (int) file_get_contents(self::marker())) : null,
            'log_tail' => self::tailLog(),
            'boot_notes' => self::$log,
        ];
    }

    public static function tailLog(int $lines = 25): string
    {
        if (! is_file(self::logPath())) {
            return '';
        }

        $all = preg_split('/\R/', (string) file_get_contents(self::logPath())) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    /**
     * Where in the bucket this environment's replica lives.
     *
     * Derived from the app URL so two environments of the same application
     * cannot collide on one prefix, which would be a two-writer corruption.
     */
    public static function replicaPath(): string
    {
        $host = parse_url((string) (self::env('APP_URL') ?? 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

        return 'litestream/'.preg_replace('/[^a-z0-9.-]+/i', '-', $host);
    }

    private static function bucket(): ?string
    {
        return self::env('AWS_BUCKET');
    }

    private static function endpoint(): ?string
    {
        return self::env('AWS_ENDPOINT') ?? self::env('AWS_ENDPOINT_URL') ?? self::env('AWS_URL');
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function note(string $message): void
    {
        self::$log[] = gmdate('H:i:s').' '.$message;
    }

    /**
     * @param  array<int, string>  $argv
     * @return array{code: int, output: string}
     */
    public static function run(array $argv, int $timeout = 120): array
    {
        $command = implode(' ', array_map('escapeshellarg', $argv)).' 2>&1';

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            return ['code' => -1, 'output' => 'could not start: '.$command];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }
}

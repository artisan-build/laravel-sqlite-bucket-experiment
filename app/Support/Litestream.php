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
 *   2. run migrations, so the schema lands on the restored file,
 *   3. start `litestream replicate` as a detached daemon,
 *   4. drop a readiness marker that every later request short-circuits on.
 *
 * A restore that fails ABORTS the boot. Starting empty on top of a live replica
 * would let Litestream replicate the empty database over the real one, which is
 * the one failure mode that destroys data rather than merely losing a request.
 *
 * Deliberately free of Laravel helpers: this runs before the framework boots,
 * which also means it cannot rely on Laravel having loaded .env yet.
 */
final class Litestream
{
    /** How long to wait for the daemon's control socket to appear, in seconds. */
    private const float DAEMON_TIMEOUT = 15.0;

    /** @var array<int, string> */
    private static array $log = [];

    /** @var array<string, string>|null */
    private static ?array $dotenv = null;

    /**
     * Make the database safe to open. Safe — and cheap — to call on every request.
     *
     * @throws RuntimeException when the database cannot be made trustworthy
     */
    public static function boot(): void
    {
        // The directory is created whether or not replication is on: without a
        // bucket the app still has to be able to open a SQLite file somewhere,
        // and on Cloud that somewhere does not exist in a fresh container.
        SqlitePath::ensureDirectory();

        if (! self::onInstance() || self::ready()) {
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

            if (self::enabled()) {
                self::writeConfig();
                self::restore();
            }

            self::migrate();

            if (self::enabled()) {
                self::startDaemon();
            }

            file_put_contents(self::marker(), (string) time());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Is a bucket attached and the binary shipped?
     *
     * This is the whole of the app's configuration surface: attaching a bucket
     * is what makes Cloud hand the app a bucket, which turns replication on.
     */
    public static function enabled(): bool
    {
        return self::disk() !== null && is_executable(self::binary());
    }

    /**
     * Am I the long-lived application instance, or a throwaway Cloud container?
     *
     * Measured on 2026-10-05: Cloud's build container sets LARAVEL_CLOUD_CI and
     * its deploy-command container sets LARAVEL_CLOUD_DEPLOY; the application
     * instance sets neither. Both of those containers get the bucket handed to
     * them and are gone within a minute, so restoring and replicating from
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
        return self::base().'/bin/litestream';
    }

    public static function base(): string
    {
        return dirname(__DIR__, 2);
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
        return self::dir().'/boot.ready';
    }

    /** Prepared, and still replicating if it is supposed to be. */
    public static function ready(): bool
    {
        if (! is_file(self::marker())) {
            return false;
        }

        return ! self::enabled() || self::daemonPid() !== null;
    }

    public static function daemonPid(): ?int
    {
        if (! is_file(self::pidPath())) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents(self::pidPath()));

        return $pid > 0 && self::alive($pid) ? $pid : null;
    }

    private static function alive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return is_dir('/proc/'.$pid);
    }

    /**
     * The replica config.
     *
     * No credential is written here. Litestream takes the key and secret from
     * the environment of the process we start, which keeps them off the
     * filesystem and out of anybody's `ps` output.
     */
    public static function config(): string
    {
        $disk = self::disk() ?? [];

        $lines = [
            '# Generated by App\Support\Litestream. Credentials come from the process environment.',
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
            '      bucket: '.($disk['bucket'] ?? ''),
            '      path: '.self::replicaPath(),
            '      region: '.($disk['region'] ?? 'auto'),
            '      force-path-style: true',
            '      sync-interval: 1s',
        ];

        if (($disk['endpoint'] ?? null) !== null) {
            $lines[] = '      endpoint: '.$disk['endpoint'];
        }

        return implode("\n", $lines)."\n";
    }

    private static function writeConfig(): void
    {
        file_put_contents(self::configPath(), self::config());
        @chmod(self::configPath(), 0o600);
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
        ], env: self::credentials());

        if ($result['code'] !== 0) {
            throw new RuntimeException(
                'litestream restore failed with exit code '.$result['code'].
                ' — refusing to boot on an empty database over a live replica: '.
                trim($result['output'])
            );
        }

        self::note('restore exit 0; database '.(is_file(SqlitePath::resolve()) ? 'restored from replica' : 'absent (replica empty)'));
    }

    /**
     * Migrate here rather than in a deploy command.
     *
     * Measured on 2026-10-05: a Cloud deploy command runs in its own Kubernetes
     * pod with its own filesystem, so migrating there touches a database no
     * instance will ever see. Running it after the restore, under the same lock,
     * is what guarantees the schema lands on the file that was just pulled from
     * the bucket.
     */
    private static function migrate(): void
    {
        if (! is_file(SqlitePath::resolve())) {
            touch(SqlitePath::resolve());
        }

        $result = self::run([PHP_BINARY, self::base().'/artisan', 'migrate', '--force', '--no-interaction'], env: ['LITESTREAM_SKIP' => '1']);

        if ($result['code'] !== 0) {
            throw new RuntimeException('migrate failed after restore: '.trim($result['output']));
        }

        self::note('migrate exit 0');
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

        $result = self::run(command: $command, env: self::credentials());
        $pid = (int) trim($result['output']);

        if ($pid <= 0) {
            throw new RuntimeException('litestream replicate could not be started: '.trim($result['output']));
        }

        file_put_contents(self::pidPath(), (string) $pid);

        $deadline = microtime(true) + self::DAEMON_TIMEOUT;

        while (microtime(true) < $deadline) {
            if (file_exists(self::socketPath())) {
                self::note('daemon up at pid '.$pid);

                return;
            }

            if (! self::alive($pid)) {
                throw new RuntimeException('litestream replicate exited immediately: '.self::tailLog());
            }

            usleep(100_000);
        }

        throw new RuntimeException(
            'litestream replicate did not open its control socket within '.self::DAEMON_TIMEOUT.'s: '.self::tailLog()
        );
    }

    /** Ask the running daemon to flush now. Used by the probe endpoints. */
    public static function sync(): array
    {
        return self::run([self::binary(), 'sync', '-config', self::configPath(), SqlitePath::resolve()], env: self::credentials());
    }

    public static function status(): array
    {
        return self::run([self::binary(), 'status', '-config', self::configPath()], env: self::credentials());
    }

    public static function ltx(): array
    {
        return self::run([self::binary(), 'ltx', '-config', self::configPath(), SqlitePath::resolve()], env: self::credentials());
    }

    /** @return array<string, mixed> */
    public static function report(): array
    {
        $disk = self::disk();

        return [
            'enabled' => self::enabled(),
            'on_instance' => self::onInstance(),
            'binary_present' => is_executable(self::binary()),
            'version' => is_executable(self::binary()) ? trim(self::run([self::binary(), 'version'])['output']) : null,
            'bucket' => $disk['bucket'] ?? null,
            'endpoint_host' => isset($disk['endpoint']) ? parse_url($disk['endpoint'], PHP_URL_HOST) : null,
            'region' => $disk['region'] ?? null,
            'credentials_present' => ($disk['key'] ?? null) !== null && ($disk['secret'] ?? null) !== null,
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

    /**
     * The bucket Cloud handed this app.
     *
     * A Laravel app on Cloud does NOT get AWS_* variables the way a Go or Rust
     * app does — measured 2026-10-05. It gets one LARAVEL_CLOUD_DISK_CONFIG JSON
     * blob, which the framework's own CloudBootstrapper turns into a filesystem
     * disk. We read the same blob. The AWS_* branch is the fallback for every
     * other host, including a plain server.
     *
     * @return array{bucket: string, endpoint: ?string, region: string, key: ?string, secret: ?string}|null
     */
    public static function disk(): ?array
    {
        if (($raw = self::env('LARAVEL_CLOUD_DISK_CONFIG')) !== null) {
            $disks = json_decode($raw, true);

            foreach (is_array($disks) ? $disks : [] as $disk) {
                if (($disk['scoped_disk'] ?? false) || blank($disk['bucket'] ?? null)) {
                    continue;
                }

                return [
                    'bucket' => (string) $disk['bucket'],
                    'endpoint' => $disk['endpoint'] ?? null,
                    'region' => (string) ($disk['region'] ?? 'auto'),
                    'key' => $disk['access_key_id'] ?? null,
                    'secret' => $disk['access_key_secret'] ?? null,
                ];
            }
        }

        if (($bucket = self::env('AWS_BUCKET')) !== null) {
            return [
                'bucket' => $bucket,
                'endpoint' => self::env('AWS_ENDPOINT') ?? self::env('AWS_ENDPOINT_URL') ?? self::env('AWS_URL'),
                'region' => self::env('AWS_DEFAULT_REGION') ?? self::env('AWS_REGION') ?? 'auto',
                'key' => self::env('AWS_ACCESS_KEY_ID'),
                'secret' => self::env('AWS_SECRET_ACCESS_KEY'),
            ];
        }

        return null;
    }

    /**
     * Credentials for a child process, passed through its environment so they
     * never reach a config file or a command line.
     *
     * @return array<string, string>
     */
    private static function credentials(): array
    {
        $disk = self::disk();

        if ($disk === null || $disk['key'] === null || $disk['secret'] === null) {
            return [];
        }

        return [
            'AWS_ACCESS_KEY_ID' => $disk['key'],
            'AWS_SECRET_ACCESS_KEY' => $disk['secret'],
            'AWS_REGION' => $disk['region'],
            'AWS_DEFAULT_REGION' => $disk['region'],
            'LITESTREAM_SKIP' => '1',
        ];
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }

        // public/index.php runs this before the framework has read .env, and
        // Cloud delivers some of its configuration through that file.
        if (! is_string($value) || $value === '') {
            $value = self::dotenv()[$key] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
    private static function dotenv(): array
    {
        if (self::$dotenv !== null) {
            return self::$dotenv;
        }

        self::$dotenv = [];

        if (! is_readable($file = self::base().'/.env')) {
            return self::$dotenv;
        }

        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            if (! str_contains($line, '=') || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);

            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            self::$dotenv[trim($key)] = $value;
        }

        return self::$dotenv;
    }

    private static function note(string $message): void
    {
        self::$log[] = gmdate('H:i:s').' '.$message;
    }

    /**
     * Run a child process, optionally with extra environment variables.
     *
     * @param  array<int, string>|null  $argv
     * @param  array<string, string>  $env
     * @return array{code: int, output: string}
     */
    public static function run(?array $argv = null, string $command = '', array $env = []): array
    {
        $command = $argv !== null
            ? implode(' ', array_map('escapeshellarg', $argv)).' 2>&1'
            : $command;

        $environment = $env === [] ? null : array_merge(
            array_filter($_SERVER, 'is_string'),
            array_filter($_ENV, 'is_string'),
            $env
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);

        if (! is_resource($process)) {
            return ['code' => -1, 'output' => 'could not start: '.$command];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'output' => $output];
    }
}

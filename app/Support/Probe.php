<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything the experiment needs to know about the machine it is running on.
 *
 * The whole point of this app is to answer "where did that run, and against
 * which database file?", so every answer is gathered in one place and rendered
 * identically from an HTTP request, from an artisan command and from a build or
 * deploy hook. Comparing two of these side by side is the measurement.
 */
final class Probe
{
    /**
     * Environment variable names whose VALUES are safe to render.
     *
     * The app is public and Cloud injects real bucket credentials, so the probe
     * works the other way round from the usual redaction list: a value is shown
     * only if it is on this list, and everything else is reported as a name with
     * a length. A new injected key therefore cannot leak by being unanticipated.
     */
    private const array PUBLISHABLE = [
        'APP_ENV', 'APP_DEBUG', 'APP_NAME', 'APP_URL', 'APP_LOCALE', 'APP_MAINTENANCE_DRIVER',
        'AWS_BUCKET', 'AWS_DEFAULT_REGION', 'AWS_REGION', 'AWS_USE_PATH_STYLE_ENDPOINT',
        'AUTORUN_ENABLED', 'AUTORUN_LARAVEL_MIGRATION', 'BROADCAST_CONNECTION', 'CACHE_STORE',
        'DB_CONNECTION', 'DB_DATABASE', 'DOCUMENT_ROOT',
        'LARAVEL_CLOUD_APP_NAME', 'LARAVEL_CLOUD_BUILD_NUMBER', 'LARAVEL_CLOUD_CI',
        'LARAVEL_CLOUD_COMMIT', 'LARAVEL_CLOUD_COMMIT_SHA', 'LARAVEL_CLOUD_DEPLOY',
        'LARAVEL_CLOUD_ENV_BRANCH', 'LARAVEL_CLOUD_ENV_NAME', 'LARAVEL_CLOUD_REGION',
        'LITESTREAM_SKIP', 'PHP_FPM_PM_MAX_CHILDREN', 'SSL_MODE',
        'FILESYSTEM_DISK', 'HOME', 'HOSTNAME', 'LARAVEL_CLOUD', 'LITESTREAM_DB_PATH',
        'LOG_CHANNEL', 'LOG_LEVEL', 'OCTANE_SERVER', 'PATH', 'PWD', 'QUEUE_CONNECTION',
        'SERVER_SOFTWARE', 'SESSION_DRIVER', 'USER',
    ];

    /** @return array<string, mixed> */
    public static function snapshot(string $context): array
    {
        $db = SqlitePath::resolve();

        return [
            'context' => $context,
            'at' => gmdate('c'),
            'host' => gethostname(),
            'machine_id' => self::machineId(),
            'pid' => getmypid(),
            'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
            'sapi' => PHP_SAPI,
            'php' => PHP_VERSION,
            'uname' => php_uname('a'),
            'cwd' => getcwd(),
            'user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null,
            'release' => self::release(),
            'db' => self::database($db),
            'litestream' => Litestream::report(),
            'env_keys' => self::envKeys(),
            'dotenv_keys' => self::dotenvKeys(),
            'writable' => self::writable(),
        ];
    }

    /** The journal mode the live connection is actually in. */
    public static function journalMode(): string
    {
        try {
            return (string) (DB::select('PRAGMA journal_mode')[0]->journal_mode ?? 'unknown');
        } catch (Throwable $e) {
            return 'unavailable: '.$e->getMessage();
        }
    }

    /**
     * A stable identity for the container, so two snapshots can be compared.
     *
     * The cgroup path carries the container id on Docker-like runtimes and is
     * readable without any privilege; the boot id changes per kernel namespace.
     */
    public static function machineId(): ?string
    {
        // boot_id first: on Cloud the cgroup path is identical in the build
        // container, the deploy container and the instance, so hashing it makes
        // three different machines look like one. Measured 2026-10-05.
        foreach (['/proc/sys/kernel/random/boot_id', '/proc/self/cgroup'] as $path) {
            if (is_readable($path) && ($raw = @file_get_contents($path)) !== false && trim($raw) !== '') {
                return substr(hash('sha256', trim($raw)), 0, 16);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function database(string $path): array
    {
        $exists = is_file($path);

        return [
            'path' => $path,
            'exists' => $exists,
            'bytes' => $exists ? filesize($path) : 0,
            'mtime' => $exists ? gmdate('c', (int) filemtime($path)) : null,
            'wal_bytes' => is_file($path.'-wal') ? filesize($path.'-wal') : null,
            'shm_present' => is_file($path.'-shm'),
            'dir_writable' => is_dir(dirname($path)) && is_writable(dirname($path)),
        ];
    }

    /** @return array<string, string|null> */
    private static function release(): array
    {
        return [
            'sha' => getenv('LARAVEL_CLOUD_COMMIT_SHA') ?: null,
            'build' => getenv('LARAVEL_CLOUD_BUILD_NUMBER') ?: null,
            'marker' => is_file($f = base_path('.release')) ? trim((string) file_get_contents($f)) : null,
        ];
    }

    /**
     * Every environment variable name, with values only for PUBLISHABLE keys.
     *
     * @return array<string, string>
     */
    private static function envKeys(): array
    {
        $out = [];

        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            $out[$key] = in_array($key, self::PUBLISHABLE, true)
                ? (string) $value
                : '<set, '.strlen((string) $value).' bytes>';
        }

        ksort($out);

        return $out;
    }

    /**
     * The NAMES of the keys in the .env Cloud drops into the container.
     *
     * Cloud copies /opt/cloud/.env over /var/www/html/.env at container start,
     * and some of what it delivers arrives only that way — which matters because
     * the Litestream shim runs before the framework has read the file.
     *
     * @return array<int, string>
     */
    private static function dotenvKeys(): array
    {
        if (! is_readable($file = base_path('.env'))) {
            return [];
        }

        $keys = [];

        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            if (str_contains($line, '=') && ! str_starts_with(ltrim($line), '#')) {
                $keys[] = trim(explode('=', $line, 2)[0]);
            }
        }

        sort($keys);

        return $keys;
    }

    /** @return array<string, bool> */
    private static function writable(): array
    {
        $out = [];

        foreach (['/tmp', base_path(), base_path('database'), storage_path(), storage_path('framework'), '/var/www', '/'] as $dir) {
            $out[$dir] = is_dir($dir) && is_writable($dir);
        }

        return $out;
    }
}

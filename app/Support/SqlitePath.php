<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where the SQLite file lives — decided by the app, not by the operator.
 *
 * Standing rule: an installer must never be asked to set an environment
 * variable, so the path is derived. On Laravel Cloud the deployed application
 * directory is not a safe place to keep a database (it is replaced wholesale on
 * every deploy and may be read-only), so the file goes under a fixed directory
 * on the instance's own disk, which is where Litestream restores it to.
 */
final class SqlitePath
{
    public const string CLOUD_DIRECTORY = '/tmp/sqlite-bucket';

    private static ?string $resolved = null;

    public static function resolve(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        // An explicit override exists only so the test suite and the local
        // `php artisan` runs can point somewhere harmless. It is not something
        // a Cloud install ever has to set.
        if (($override = getenv('LITESTREAM_DB_PATH')) !== false && $override !== '') {
            return self::$resolved = $override;
        }

        return self::$resolved = self::onCloud()
            ? self::CLOUD_DIRECTORY.'/database.sqlite'
            : self::basePath().'/database/database.sqlite';
    }

    public static function directory(): string
    {
        return dirname(self::resolve());
    }

    public static function ensureDirectory(): void
    {
        $dir = self::directory();

        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * Running on a Laravel Cloud instance?
     *
     * Cloud injects LARAVEL_CLOUD; the writability fallback covers a runtime
     * that has not been identified yet and would otherwise put the database
     * somewhere it cannot be written.
     */
    public static function onCloud(): bool
    {
        if (getenv('LARAVEL_CLOUD') !== false && getenv('LARAVEL_CLOUD') !== '') {
            return true;
        }

        return ! is_writable(self::basePath().'/database');
    }

    private static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Only for tests: forget the memoised answer. */
    public static function flush(): void
    {
        self::$resolved = null;
    }
}

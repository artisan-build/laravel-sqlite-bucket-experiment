<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | This application's content is a directory of markdown files, compiled into
    | a SQLite file by `content:build` during the Laravel Cloud BUILD and shipped
    | with the deployment artifact. There is no managed database, so `content` is
    | both the default and the only connection, and it is opened READ-ONLY.
    |
    | Deliberately NOT env-driven. Every value here is a property of the
    | application, not of the environment it runs in, and an installer must never
    | be asked to set an environment variable to make the app work.
    |
    */

    'default' => 'content',

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'content' => [
            'driver' => 'sqlite',

            // A SQLite URI, not a path. `mode=ro` opens the file with
            // SQLITE_OPEN_READONLY, so a write is refused by the library rather
            // than by convention; `immutable=1` additionally promises the file
            // never changes under us (true: it is built once, in the build, and
            // the container's filesystem is replaced on every deploy), which
            // means SQLite takes no locks and creates no -wal/-shm sidecar
            // files next to a file it cannot write anyway.
            //
            // Laravel passes a `file:`-prefixed database through to the DSN
            // untouched (Illuminate\Database\Connectors\SQLiteConnector::
            // parseDatabasePath), so this needs no custom connector.
            'database' => 'file:'.database_path('content.sqlite').'?mode=ro&immutable=1',

            'prefix' => '',
            'foreign_key_constraints' => false,

            // No journal_mode / synchronous / busy_timeout: every one of those
            // pragmas is a write, and there is no writer to wait for.
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | Kept at its default for the framework's benefit. Nothing migrates here:
    | the schema is created by `content:build`, and a `migrate` anywhere in the
    | deploy path would fail against a read-only connection.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | None. No cache, queue or session store in this application touches Redis.
    |
    */

    'redis' => [],

];

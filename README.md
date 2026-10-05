# A docs site with no database

A throwaway experiment on the `read-only-markdown` branch: a stock Laravel 13 app
running on [Laravel Cloud](https://cloud.laravel.com) with **no managed database,
no cache, no queue and no object storage attached**.

The content is 20 markdown files in [`content/`](content). An artisan command
compiles them into a SQLite file during the Cloud **build**, and the application
opens that file **read-only**.

```sh
php artisan content:build      # content/*.md -> database/content.sqlite
```

## How it fits together

| Piece | Where |
|---|---|
| The content | `content/*.md`, with `title`, `slug`, `date`, `summary` front matter |
| The compiler | `app/Content/ContentBuilder.php` — posts table + an external-content FTS5 index |
| The command | `php artisan content:build`, run in the Cloud **build command** |
| The connection | `config/database.php` — `file:…/content.sqlite?mode=ro&immutable=1`, the only connection, and the default |
| The site | `app/Http/Controllers/PostController.php`, `routes/web.php`, `resources/views/posts/` |

Three things make it work on Cloud specifically:

1. **The import runs in the BUILD, never in a deploy command.** A Cloud deploy
   command runs in its own Kubernetes pod whose filesystem no instance ever sees,
   so a `content:build` there would compile a database and throw it away.
2. **The file lives inside the app root** (`database/content.sqlite`), because
   build writes outside `/var/www/html` do not survive into the image.
3. **Nothing defaults to the `database` driver.** Sessions are cookies, the cache
   is files, the queue is `sync` — as literals in committed config, so no
   environment variable can point them at a database that cannot be written.

There is no `migrate` anywhere in the deploy path, and nothing to back up: the
markdown is the source of truth and the database is a build artifact.

## Read-only for real

```php
'database' => 'file:'.database_path('content.sqlite').'?mode=ro&immutable=1',
```

`mode=ro` opens with `SQLITE_OPEN_READONLY`, so a write fails with *attempt to
write a readonly database* rather than by convention. `immutable=1` promises the
file cannot change while open — true, since it is built once and the container's
filesystem is replaced on the next deploy — so SQLite takes no locks and creates
no `-wal`/`-shm` sidecars beside a file it could not write anyway. Laravel passes
a `file:`-prefixed database through to the DSN untouched, so no custom connector
is needed.

`/probe/write` on the running site tries four write paths and reports what each
one did. `/probe/info` reports the file, the pragmas and the drivers in use.

## Running it locally

```sh
composer install
cp .env.example .env && php artisan key:generate
php artisan content:build
php artisan serve
```

```sh
php artisan test        # 32 tests, including that the build is reproducible
vendor/bin/pint --test
```

## The other branch

`main` holds the first half of this experiment: the same app keeping a *writable*
SQLite database on the instance and replicating it to a Cloud bucket with
Litestream. That works, and Cloud's overlapping deploys lose writes through it.
This branch asks what happens if nothing writes at all.

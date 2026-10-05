---
title: 'Laravel passes a file: URI straight through'
slug: laravel-connection
date: '2026-02-05'
summary: 'No custom connector required.'
---

Laravel's `SQLiteConnector` normally calls `realpath()` on the configured
database and throws if it does not exist. But it returns the value untouched when
it starts with `file:`, so a URI works in plain configuration:

```php
'content' => [
    'driver' => 'sqlite',
    'database' => 'file:'.database_path('content.sqlite').'?mode=ro&immutable=1',
],
```

Leave out `journal_mode`, `synchronous` and `busy_timeout` — every one of those
pragmas is a write.

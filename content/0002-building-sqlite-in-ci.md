---
title: 'Building the SQLite file during the build'
slug: building-sqlite-in-ci
date: '2026-01-09'
summary: 'The build is the only place that may write.'
---

On Laravel Cloud the deploy command runs in its own Kubernetes pod. Its
filesystem is destroyed seconds later, and no instance ever sees it. So a file an
instance needs has to be produced by the **build** command, which writes into the
deployment artifact.

```sh
composer install --no-dev --optimize-autoloader
php artisan content:build
```

That is the whole recipe. The artifact ships with `database/content.sqlite` inside
it, and every instance of every deploy gets a byte-identical copy.

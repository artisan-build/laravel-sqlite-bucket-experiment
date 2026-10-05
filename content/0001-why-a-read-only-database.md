---
title: 'Why a docs site wants a read-only database'
slug: why-a-read-only-database
date: '2026-01-06'
summary: 'A docs site has one writer, and it is a build.'
---

A documentation site is a strange kind of application: it has no users who
write anything. Every row it serves was decided by somebody editing a file in a
repository, reviewed in a pull request, and merged. The database is not where the
truth lives — the markdown is.

Once you accept that, the database stops being infrastructure you have to keep
alive and becomes a **build artifact**, like compiled CSS. You can rebuild it from
nothing at any time, so losing it costs nothing.

## What follows from it

* No backups. The content directory *is* the backup.
* No migrations in the deploy path. The schema is created from scratch each build.
* No connection pool to size, no credentials to rotate, no managed database bill.
* The file can be opened `SQLITE_OPEN_READONLY`, which turns "nothing writes here"
  from a convention into something the library enforces.

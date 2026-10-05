---
title: 'Sessions without a database'
slug: sessions-without-a-database
date: '2026-01-24'
summary: 'Cookie sessions, and why file sessions are a trap.'
---

The Laravel skeleton defaults sessions, the cache and the queue to the
`database` driver. With no writable database, all three have to change — in
committed configuration, never by asking somebody to set an environment variable.

* **Sessions: `cookie`.** Encrypted in the client, so any instance can read them.
* **Cache: `file`.** Per-instance and lost on deploy, which is fine as long as
  nothing treats it as a source of truth.
* **Queue: `sync`.** A docs site has no jobs; `sync` makes an accidental one fail
  in the request instead of writing to a table that does not exist.

`file` sessions would *work* and are the trap: they live on a container filesystem
that is replaced on every deploy, so every deploy logs everybody out.

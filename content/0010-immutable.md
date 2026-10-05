---
title: 'mode=ro and immutable=1'
slug: immutable
date: '2026-02-02'
summary: 'Two different promises in one URI.'
---

```
file:/var/www/html/database/content.sqlite?mode=ro&immutable=1
```

`mode=ro` opens with `SQLITE_OPEN_READONLY`: writes fail with *attempt to write a
readonly database*. `immutable=1` additionally promises the file cannot change
while open, so SQLite takes no locks at all and creates no `-wal` or `-shm`
sidecar files — which matters, because it could not create them anyway.

Only claim `immutable` when it is true. Here it is: the file is built once, in the
build, and the filesystem is replaced wholesale on the next deploy.

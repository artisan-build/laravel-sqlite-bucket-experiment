---
title: 'What to monitor'
slug: observability
date: '2026-02-26'
summary: 'Three things, and none of them is a database.'
---

1. Did the build produce the file, with the row count you expected?
2. Does the application open it? A missing file must fail loudly at boot, not
   serve an empty index.
3. Is a write attempt still refused? That is a one-line probe and it is the
   property the whole design rests on.

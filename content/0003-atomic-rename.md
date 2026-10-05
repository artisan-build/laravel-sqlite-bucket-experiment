---
title: 'Why the builder renames instead of writing in place'
slug: atomic-rename
date: '2026-01-12'
summary: 'A half-written database must never be visible.'
---

`content:build` writes `content.sqlite.building` and renames it into place
when it is finished. `rename(2)` within a filesystem is atomic: a reader sees the
old file or the new one, never a torn one.

In the build this is belt-and-braces — nothing is reading the file yet. It matters
if you ever run the same builder on a live box, which is exactly the mistake a
recipe should make impossible rather than warn about.

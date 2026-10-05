---
title: 'Scale to zero costs one cold start'
slug: hibernation
date: '2026-01-30'
summary: 'There is nothing to restore on wake.'
---

Hibernation is the part a database on the instance makes expensive. If the file
has to be pulled back from object storage before the framework can open a
connection, every cold start pays for a restore, and the restore has to finish
before the first visitor gets a byte.

A read-only build artifact removes that step entirely. The file is already inside
the image, so waking costs what a PHP boot costs plus a cold page cache. Nothing
is fetched, nothing is restored, and there is no window in which the application
is up but the data is not.

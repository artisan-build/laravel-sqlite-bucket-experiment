---
title: 'What you stop paying for'
slug: no-managed-database
date: '2026-01-21'
summary: 'No managed database, cache, queue or bucket.'
---

The interesting part of this experiment is the list of resources the
environment does **not** have attached: no managed database, no cache, no queue,
no object storage. The bill is one application instance.

Whether that saves real money depends on your host. Where a managed database is a
fixed monthly line, it saves that line. Where it is serverless and scales to zero,
a small site's database already rounds to pennies and the saving is mostly
conceptual: fewer moving parts rather than fewer dollars.

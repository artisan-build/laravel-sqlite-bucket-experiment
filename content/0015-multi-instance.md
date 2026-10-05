---
title: 'Scaling out is copying a file'
slug: multi-instance
date: '2026-02-17'
summary: 'Every instance has its own complete database.'
---

Horizontal scaling is the quiet win. Each instance has a private copy of the
database on local disk, so reads do not cross the network and there is no shared
database to contend on. Ten instances mean ten copies of a file that is measured
in kilobytes.

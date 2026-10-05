---
title: 'Overlapping containers stop being scary'
slug: deploys-overlap
date: '2026-01-27'
summary: 'Two identical read-only copies cannot disagree.'
---

Laravel Cloud brings the new container up several seconds before the old one
drains, so for a moment two containers serve the same site. With a database on the
instance and a writer, that overlap is a data-loss window — two writers, one
replica.

With a read-only build artifact there is no writer, and the two containers hold
different *versions* rather than a contested one. A request served by either is
internally consistent. The worst case is a reader who sees version N and then N-1
on the next request, for a few seconds, once per deploy.

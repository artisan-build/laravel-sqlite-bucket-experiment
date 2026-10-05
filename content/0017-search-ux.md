---
title: 'Search that is a SELECT'
slug: search-ux
date: '2026-02-23'
summary: 'No index to keep warm.'
---

Because search is a query against a file on local disk, it needs no service,
no sync job and no eventual consistency. The index is exactly as old as the
deployment, which is the only version of the content that exists.

---
title: 'A reproducible build artifact'
slug: determinism
date: '2026-02-14'
summary: 'The same content compiles to the same bytes.'
---

The builder sorts its input by filename, assigns row ids in that order, and
stores no timestamp. Two runs over the same content produce byte-identical files,
which is worth a test: it means a rebuild is never a content change, and a diff in
the artifact always means a diff in the content.

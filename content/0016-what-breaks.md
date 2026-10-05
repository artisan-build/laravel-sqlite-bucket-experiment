---
title: 'What this architecture cannot do'
slug: what-breaks
date: '2026-02-20'
summary: 'Anything a visitor writes.'
---

Be honest about the edge: comments, likes, form submissions, rate limiting,
anything per-visitor and durable. All of it needs a writable store, and the moment
you add one you are back to a managed resource — at which point the question is
whether the content should live there too.

The shape fits docs, blogs, changelogs, marketing pages and reference material. It
does not fit an application.

---
title: 'Where this leaves us'
slug: verdict
date: '2026-03-04'
summary: 'Small, boring and hard to break.'
---

The architecture removes the failure modes rather than managing them. There is
no replication to lose writes, no connection to exhaust, no backup to restore, and
no state that outlives a deployment. Everything that could go wrong goes wrong at
build time, where a human is watching and the fix is a commit.

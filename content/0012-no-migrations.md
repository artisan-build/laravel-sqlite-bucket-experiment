---
title: 'No migrate, anywhere'
slug: no-migrations
date: '2026-02-08'
summary: 'The schema is a build output, not a history.'
---

There is no `migrate` in this application's deploy path, and nothing on the
platform runs one for you. The schema is created by the builder each time, so
there is no migration history to replay and no chance of a deploy pod migrating a
database no instance will see.

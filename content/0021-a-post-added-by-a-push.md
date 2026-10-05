---
title: 'A post added by a push'
slug: added-by-a-push
date: '2026-03-07'
summary: 'This file exists to be added and removed while a reader watches.'
---

This post was added to prove the content pipeline end to end: edit a markdown
file, push the branch, and the platform builds a new database and ships it. There
is no cache to clear and no command for anybody to remember.

It is also the marker for the overlap test. A reader hitting the site once a
second through a deploy can tell which version it was served by counting posts,
so adding and removing this one file makes the two versions distinguishable.

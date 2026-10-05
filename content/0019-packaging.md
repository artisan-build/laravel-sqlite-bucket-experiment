---
title: 'Could this be a package?'
slug: packaging
date: '2026-03-01'
summary: 'Mostly, and the gap is the build command.'
---

A package could ship the builder, the connection configuration, a model, a
search helper and the non-database driver defaults. What it cannot ship is the one
line that runs it during the platform's build, because on Laravel Cloud the build
command is environment configuration rather than something in the repository.

So: a package plus one documented line, or a recipe. Both are honest; neither is
"install and forget".

---
title: 'Front matter is the schema'
slug: front-matter
date: '2026-01-15'
summary: 'title, slug and date, parsed once at build time.'
---

Each file begins with YAML front matter:

```yaml
---
title: 'Front matter is the schema'
slug: front-matter
date: '2026-01-15'
summary: 'title, slug and date, parsed once at build time.'
---
```

`league/commonmark` ships a `FrontMatterExtension`, so this needs no new markdown
library. One trap: symfony/yaml evaluates a bare `2026-01-15` into a Unix
timestamp, not a string. Quote your dates, and normalise both shapes in the
builder anyway.

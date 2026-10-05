<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Read-only markdown' }}</title>
    <style>
        :root { color-scheme: light dark; --ink: #16181d; --dim: #666c7a; --line: #e3e5ea; --bg: #fdfdfc; --link: #1f5fbf; }
        @media (prefers-color-scheme: dark) {
            :root { --ink: #e8e9ec; --dim: #9aa0ad; --line: #2b2f37; --bg: #14161a; --link: #7fb0ff; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink);
            font: 16px/1.6 ui-sans-serif, -apple-system, "Segoe UI", sans-serif; }
        .wrap { max-width: 44rem; margin: 0 auto; padding: 2.5rem 1rem 5rem; }
        header { border-bottom: 1px solid var(--line); padding-bottom: 1rem; margin-bottom: 2rem;
            display: flex; gap: 1rem; align-items: baseline; flex-wrap: wrap; }
        header a.home { font-weight: 650; text-decoration: none; color: var(--ink); }
        header form { margin-left: auto; }
        input[type=search] { font: inherit; padding: .35rem .6rem; border: 1px solid var(--line);
            border-radius: .4rem; background: transparent; color: inherit; min-width: 12rem; }
        a { color: var(--link); }
        ol.posts { list-style: none; margin: 0; padding: 0; }
        ol.posts li { padding: .9rem 0; border-bottom: 1px solid var(--line); }
        ol.posts h2 { font-size: 1.05rem; margin: 0 0 .2rem; }
        time, .meta { color: var(--dim); font-size: .85rem; font-variant-numeric: tabular-nums; }
        .summary { color: var(--dim); margin: .15rem 0 0; }
        article h1 { font-size: 1.6rem; margin: 0 0 .3rem; line-height: 1.25; }
        article pre { background: color-mix(in srgb, var(--ink) 7%, transparent); padding: .8rem 1rem;
            border-radius: .5rem; overflow-x: auto; }
        article code { font-size: .9em; }
        footer { margin-top: 3rem; color: var(--dim); font-size: .8rem; border-top: 1px solid var(--line); padding-top: 1rem; }
    </style>
</head>
<body>
<div class="wrap" data-testid="content-page">
    <header data-testid="content-header">
        <a class="home" href="{{ route('posts.index') }}">read-only markdown</a>
        <form action="{{ route('posts.search') }}" method="get" data-testid="content-search-form">
            <input type="search" name="q" value="{{ $terms ?? '' }}" placeholder="Search" aria-label="Search">
        </form>
    </header>

    {{ $slot }}

    <footer data-testid="content-footer">
        Served from a read-only SQLite file compiled from markdown at build time.
        No managed database, cache, queue or bucket.
    </footer>
</div>
</body>
</html>

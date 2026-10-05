<x-layout title="Search" :terms="$terms">
    <section data-testid="posts-search">
        <h1>Search</h1>
        <p class="meta" data-testid="posts-search-summary">
            {{ $results->count() }} {{ Str::plural('result', $results->count()) }}
            @if ($terms !== '') for &ldquo;{{ $terms }}&rdquo; @endif
            &middot; {{ $fullText ? 'FTS5' : 'LIKE fallback' }}
        </p>
        <ol class="posts">
            @foreach ($results as $result)
                <li data-testid="posts-search-result">
                    <h2><a href="{{ route('posts.show', $result->slug) }}">{{ $result->title }}</a></h2>
                    <time datetime="{{ $result->date }}">{{ $result->date }}</time>
                    @if ($result->summary)
                        <p class="summary">{{ $result->summary }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
</x-layout>

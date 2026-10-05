<x-layout :title="$post->title">
    <article data-testid="posts-show">
        <h1 data-testid="posts-show-title">{{ $post->title }}</h1>
        <time datetime="{{ $post->date }}">{{ $post->date }}</time>
        <div data-testid="posts-show-body">
            {{-- Rendered from markdown at build time by content:build, with
                 html_input=escape and unsafe links disabled. --}}
            {!! $post->html !!}
        </div>
        <p class="meta" data-testid="posts-show-source">source: content/{{ $post->source }}</p>
    </article>
</x-layout>

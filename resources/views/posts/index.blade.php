<x-layout title="Read-only markdown">
    <section data-testid="posts-index">
        <ol class="posts">
            @foreach ($posts as $post)
                <li data-testid="posts-index-item">
                    <h2><a href="{{ route('posts.show', $post->slug) }}">{{ $post->title }}</a></h2>
                    <time datetime="{{ $post->date }}">{{ $post->date }}</time>
                    @if ($post->summary)
                        <p class="summary">{{ $post->summary }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
        <p class="meta" data-testid="posts-index-count">{{ $posts->count() }} posts</p>
    </section>
</x-layout>

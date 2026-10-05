<?php

declare(strict_types=1);

namespace App\Content;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search over the compiled content.
 *
 * FTS5 when the build managed to create the index, and a LIKE scan when the PHP
 * build has no FTS5 module. The fallback is not a nicety: it means the site still
 * works if the runtime's SQLite differs from the builder's, instead of 500ing on
 * a missing virtual table.
 */
final class PostSearch
{
    public function __construct(private readonly string $connection = 'content') {}

    public function enabled(): bool
    {
        return DB::connection($this->connection)
            ->table('meta')->where('key', 'fts')->value('value') === '1';
    }

    /** @return Collection<int, object> */
    public function search(string $terms, int $limit = 20): Collection
    {
        $terms = trim($terms);

        if ($terms === '') {
            return collect();
        }

        return $this->enabled()
            ? $this->fullText($terms, $limit)
            : $this->like($terms, $limit);
    }

    /** @return Collection<int, object> */
    private function fullText(string $terms, int $limit): Collection
    {
        // Every word becomes a prefix term, quoted, so that punctuation a visitor
        // types cannot reach FTS5's query syntax and turn a search into an error.
        $query = collect(preg_split('/\s+/', $terms, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $word) => '"'.str_replace('"', '', $word).'"*')
            ->implode(' ');

        if ($query === '') {
            return collect();
        }

        return DB::connection($this->connection)
            ->table('posts_fts')
            ->join('posts', 'posts.id', '=', 'posts_fts.rowid')
            ->whereRaw('posts_fts match ?', [$query])
            ->orderByRaw('rank')
            ->limit($limit)
            ->get(['posts.slug', 'posts.title', 'posts.date', 'posts.summary']);
    }

    /** @return Collection<int, object> */
    private function like(string $terms, int $limit): Collection
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $terms).'%';

        return DB::connection($this->connection)
            ->table('posts')
            ->where(fn ($query) => $query
                ->where('title', 'like', $like)
                ->orWhere('summary', 'like', $like)
                ->orWhere('html', 'like', $like))
            ->orderByDesc('date')
            ->limit($limit)
            ->get(['slug', 'title', 'date', 'summary']);
    }
}

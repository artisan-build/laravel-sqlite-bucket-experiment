<?php

declare(strict_types=1);

namespace App\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use PDO;
use RuntimeException;

/**
 * Compiles a directory of markdown files into a single SQLite file.
 *
 * This runs in the Laravel Cloud BUILD, never on an instance: a Cloud deploy
 * command runs in its own Kubernetes pod whose filesystem no instance ever sees,
 * so anything the application needs on disk has to be produced by the build and
 * shipped inside the deployment artifact.
 *
 * The builder owns the only writable handle to the file that will ever exist.
 * It writes to a temporary name and renames it into place, so a half-built
 * database is never visible under the name the application opens.
 */
final class ContentBuilder
{
    /** Schema version, stored in `meta`, so a reader can refuse a shape it does not know. */
    public const int SCHEMA = 1;

    public function __construct(
        private readonly string $contentDirectory,
        private readonly string $target,
    ) {}

    /**
     * @return array{posts: int, bytes: int, fts: bool, seconds: float, target: string}
     */
    public function build(): array
    {
        $started = microtime(true);

        $posts = $this->read();

        if ($posts === []) {
            throw new RuntimeException("No markdown files found in {$this->contentDirectory}");
        }

        $temporary = $this->target.'.building';

        foreach ([$temporary, $temporary.'-journal'] as $stale) {
            if (file_exists($stale)) {
                unlink($stale);
            }
        }

        if (! is_dir($directory = dirname($this->target))) {
            mkdir($directory, 0o755, true);
        }

        $pdo = new PDO('sqlite:'.$temporary, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // DELETE, not WAL: a read-only reader of a WAL database still needs to
        // write a -shm file, which it cannot. The file ships fully checkpointed.
        $pdo->exec('pragma journal_mode = delete');
        $pdo->exec('pragma page_size = 4096');

        $fts = $this->schema($pdo);
        $this->insert($pdo, $posts, $fts);

        $pdo->exec('vacuum');
        $pdo = null;

        // Atomic within the filesystem: a reader either sees the previous file
        // or the finished one, never a partial write.
        if (! rename($temporary, $this->target)) {
            throw new RuntimeException("Could not move {$temporary} into place at {$this->target}");
        }

        clearstatcache(true, $this->target);

        return [
            'posts' => count($posts),
            'bytes' => (int) filesize($this->target),
            'fts' => $fts,
            'seconds' => round(microtime(true) - $started, 3),
            'target' => $this->target,
        ];
    }

    /**
     * Every markdown file, parsed and rendered, ordered by filename.
     *
     * Ordered by filename rather than by date or by directory iteration order,
     * because the row ids have to be the same on every build for the same input
     * — a build that reshuffles its own primary keys is not reproducible.
     *
     * @return list<array<string, string>>
     */
    public function read(): array
    {
        $files = glob(rtrim($this->contentDirectory, '/').'/*.md') ?: [];
        sort($files, SORT_STRING);

        $converter = $this->converter();
        $posts = [];
        $slugs = [];

        foreach ($files as $file) {
            $rendered = $converter->convert($markdown = (string) file_get_contents($file));

            $frontMatter = $rendered instanceof RenderedContentWithFrontMatter
                ? (array) $rendered->getFrontMatter()
                : [];

            $name = basename($file, '.md');
            $slug = (string) ($frontMatter['slug'] ?? $name);

            if (isset($slugs[$slug])) {
                throw new RuntimeException("Duplicate slug '{$slug}' in {$file} (already used by {$slugs[$slug]})");
            }

            foreach (['title', 'date'] as $required) {
                if (! isset($frontMatter[$required])) {
                    throw new RuntimeException("{$file} is missing the '{$required}' front matter key");
                }
            }

            $slugs[$slug] = $file;

            $posts[] = [
                'slug' => $slug,
                'title' => (string) $frontMatter['title'],
                'date' => $this->date($frontMatter['date']),
                'summary' => (string) ($frontMatter['summary'] ?? ''),
                'html' => (string) $rendered,
                'text' => trim(html_entity_decode(strip_tags((string) $rendered), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'source' => basename($file),
                'source_bytes' => (string) strlen($markdown),
            ];
        }

        return $posts;
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new FrontMatterExtension);

        return new MarkdownConverter($environment);
    }

    /**
     * Front matter dates reach us as strings when quoted and as Unix timestamps
     * when not — symfony/yaml evaluates a bare `2026-01-15` without the
     * PARSE_DATETIME flag. Normalise both to `Y-m-d` so sorting is lexical.
     */
    private function date(mixed $value): string
    {
        if (is_int($value)) {
            return gmdate('Y-m-d', $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    /** @return bool whether the FTS5 index was created */
    private function schema(PDO $pdo): bool
    {
        $pdo->exec(<<<'SQL'
            create table posts (
                id integer primary key,
                slug text not null unique,
                title text not null,
                date text not null,
                summary text not null default '',
                html text not null,
                source text not null,
                source_bytes integer not null
            )
        SQL);

        $pdo->exec('create index posts_date_index on posts (date desc, id)');

        $pdo->exec('create table meta (key text primary key, value text not null)');

        try {
            // External-content FTS5: the index stores no copy of the row, only
            // the terms, so the file stays small and cannot disagree with posts.
            $pdo->exec(<<<'SQL'
                create virtual table posts_fts using fts5 (
                    title, summary, text,
                    content = '',
                    tokenize = 'porter unicode61'
                )
            SQL);

            return true;
        } catch (\PDOException) {
            // A PHP build without FTS5. Search falls back to LIKE; see PostSearch.
            return false;
        }
    }

    /** @param  list<array<string, string>>  $posts */
    private function insert(PDO $pdo, array $posts, bool $fts): void
    {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(
            'insert into posts (id, slug, title, date, summary, html, source, source_bytes)
             values (:id, :slug, :title, :date, :summary, :html, :source, :source_bytes)'
        );

        $index = $fts
            ? $pdo->prepare('insert into posts_fts (rowid, title, summary, text) values (:id, :title, :summary, :text)')
            : null;

        foreach ($posts as $position => $post) {
            $id = $position + 1;

            $insert->execute([
                'id' => $id,
                'slug' => $post['slug'],
                'title' => $post['title'],
                'date' => $post['date'],
                'summary' => $post['summary'],
                'html' => $post['html'],
                'source' => $post['source'],
                'source_bytes' => $post['source_bytes'],
            ]);

            $index?->execute([
                'id' => $id,
                'title' => $post['title'],
                'summary' => $post['summary'],
                'text' => $post['text'],
            ]);
        }

        $meta = $pdo->prepare('insert into meta (key, value) values (:key, :value)');

        // Deliberately no build timestamp: two builds of the same content must
        // produce the same bytes, which is what the determinism test asserts.
        foreach (['schema' => (string) self::SCHEMA, 'posts' => (string) count($posts), 'fts' => $fts ? '1' : '0'] as $key => $value) {
            $meta->execute(['key' => $key, 'value' => $value]);
        }

        $pdo->commit();
    }
}

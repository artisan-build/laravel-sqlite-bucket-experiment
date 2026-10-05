<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Content\ContentBuilder;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class ContentBuilderTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/content-builder-'.bin2hex(random_bytes(6));
        mkdir($this->workspace.'/in', 0o755, true);
        mkdir($this->workspace.'/out', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace.'/*/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->workspace.'/in/'.$name, $contents);
    }

    private function markdown(string $slug, string $title = 'A title', string $date = '2026-01-01', string $body = 'Body text.'): string
    {
        return "---\ntitle: '{$title}'\nslug: {$slug}\ndate: '{$date}'\nsummary: 'A summary.'\n---\n\n{$body}\n";
    }

    private function build(): array
    {
        return (new ContentBuilder($this->workspace.'/in', $this->workspace.'/out/content.sqlite'))->build();
    }

    private function read(string $sql): array
    {
        $pdo = new PDO('sqlite:'.$this->workspace.'/out/content.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_it_compiles_front_matter_and_rendered_html(): void
    {
        $this->write('0001-first.md', $this->markdown('first', 'First post', '2026-01-02', "# Heading\n\nSome *emphasis*."));

        $result = $this->build();

        $this->assertSame(1, $result['posts']);
        $this->assertGreaterThan(0, $result['bytes']);

        $row = $this->read('select * from posts')[0];

        $this->assertSame('first', $row['slug']);
        $this->assertSame('First post', $row['title']);
        $this->assertSame('2026-01-02', $row['date']);
        $this->assertSame('A summary.', $row['summary']);
        $this->assertStringContainsString('<h1>Heading</h1>', $row['html']);
        $this->assertStringContainsString('<em>emphasis</em>', $row['html']);
        $this->assertSame('0001-first.md', $row['source']);
    }

    public function test_the_same_content_compiles_to_byte_identical_files(): void
    {
        $this->write('0001-first.md', $this->markdown('first', 'First', '2026-01-02'));
        $this->write('0002-second.md', $this->markdown('second', 'Second', '2026-01-03'));

        $this->build();
        $first = hash_file('sha256', $this->workspace.'/out/content.sqlite');

        // A second build over the same input, writing over the first file.
        $this->build();
        $second = hash_file('sha256', $this->workspace.'/out/content.sqlite');

        $this->assertSame($first, $second, 'content:build is not reproducible');
    }

    public function test_row_ids_follow_filename_order_not_directory_order(): void
    {
        // Written out of order on purpose: the ids must still follow the names.
        $this->write('0003-c.md', $this->markdown('c', 'C', '2026-01-01'));
        $this->write('0001-a.md', $this->markdown('a', 'A', '2026-03-01'));
        $this->write('0002-b.md', $this->markdown('b', 'B', '2026-02-01'));

        $this->build();

        $this->assertSame(
            [['id' => 1, 'slug' => 'a'], ['id' => 2, 'slug' => 'b'], ['id' => 3, 'slug' => 'c']],
            array_map(
                fn (array $row) => ['id' => (int) $row['id'], 'slug' => $row['slug']],
                $this->read('select id, slug from posts order by id')
            )
        );
    }

    public function test_it_indexes_every_post_for_full_text_search(): void
    {
        $this->write('0001-first.md', $this->markdown('first', 'Hibernation', '2026-01-02', 'Scaling to zero costs one cold start.'));
        $this->write('0002-second.md', $this->markdown('second', 'Overlap', '2026-01-03', 'Two containers serve at once.'));

        $result = $this->build();

        $this->assertTrue($result['fts'], 'this PHP build has no FTS5; the search assertions below are untested');

        $hits = $this->read("select rowid from posts_fts where posts_fts match '\"container\"*'");

        $this->assertSame([['rowid' => 2]], array_map(fn ($r) => ['rowid' => (int) $r['rowid']], $hits));
    }

    public function test_it_records_the_schema_version_and_post_count(): void
    {
        $this->write('0001-first.md', $this->markdown('first'));

        $this->build();

        $meta = array_column($this->read('select key, value from meta'), 'value', 'key');

        $this->assertSame((string) ContentBuilder::SCHEMA, $meta['schema']);
        $this->assertSame('1', $meta['posts']);
    }

    public function test_it_leaves_no_partial_file_behind(): void
    {
        $this->write('0001-first.md', $this->markdown('first'));

        $this->build();

        $this->assertFileExists($this->workspace.'/out/content.sqlite');
        $this->assertFileDoesNotExist($this->workspace.'/out/content.sqlite.building');
    }

    public function test_it_refuses_a_post_with_no_title(): void
    {
        $this->write('0001-first.md', "---\nslug: first\ndate: '2026-01-01'\n---\n\nBody.\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("missing the 'title' front matter key");

        $this->build();
    }

    public function test_it_refuses_two_posts_with_the_same_slug(): void
    {
        $this->write('0001-first.md', $this->markdown('same'));
        $this->write('0002-second.md', $this->markdown('same'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate slug 'same'");

        $this->build();
    }

    public function test_it_refuses_to_build_from_an_empty_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No markdown files found');

        $this->build();
    }

    public function test_it_ships_a_fully_checkpointed_file_with_no_sidecars(): void
    {
        $this->write('0001-first.md', $this->markdown('first'));

        $this->build();

        // A WAL database would need a -shm file, which a read-only reader on a
        // read-only filesystem cannot create.
        $this->assertSame('delete', $this->read('pragma journal_mode')[0]['journal_mode']);
        $this->assertFileDoesNotExist($this->workspace.'/out/content.sqlite-wal');
        $this->assertFileDoesNotExist($this->workspace.'/out/content.sqlite-shm');
    }
}

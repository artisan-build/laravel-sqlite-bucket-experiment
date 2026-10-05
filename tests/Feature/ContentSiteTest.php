<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Content\Post;
use Tests\Support\UsesCompiledContent;
use Tests\TestCase;

final class ContentSiteTest extends TestCase
{
    use UsesCompiledContent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCompiledContent();
    }

    public function test_the_index_lists_every_post_newest_first(): void
    {
        $posts = Post::published()->get();

        $this->assertGreaterThanOrEqual(2, $posts->count(), 'content/ should hold more than one post');

        $response = $this->get('/')->assertOk();

        $response->assertSee('data-testid="posts-index"', false);
        $response->assertSeeText($posts->first()->title);
        $response->assertSeeText($posts->last()->title);
        $response->assertSeeTextInOrder([$posts->first()->title, $posts->last()->title]);
        $response->assertSeeText($posts->count().' posts');
    }

    public function test_a_post_page_renders_its_compiled_html(): void
    {
        $post = Post::published()->first();

        $response = $this->get('/posts/'.$post->slug)->assertOk();

        $response->assertSeeText($post->title);
        $response->assertSee('data-testid="posts-show-body"', false);
        $response->assertSee('source: content/'.$post->source, false);
    }

    public function test_an_unknown_slug_is_a_404(): void
    {
        $this->get('/posts/not-a-real-post')->assertNotFound();
    }

    public function test_search_finds_a_post_by_a_word_from_its_body(): void
    {
        $response = $this->get('/search?q=hibernation')->assertOk();

        $response->assertSee('data-testid="posts-search-result"', false);
        $response->assertSeeText('Scale to zero costs one cold start');
    }

    public function test_search_stems_so_a_different_form_of_the_word_still_matches(): void
    {
        $this->get('/search?q=deploying')->assertOk()->assertSee('data-testid="posts-search-result"', false);
    }

    public function test_search_with_no_terms_returns_no_results_and_does_not_error(): void
    {
        $this->get('/search')->assertOk()->assertSeeText('0 results');
    }

    public function test_search_punctuation_cannot_break_the_fts_query(): void
    {
        $this->get('/search?q='.urlencode('"OR NEAR(* *) --'))->assertOk();
    }

    public function test_the_write_probe_reports_every_write_refused(): void
    {
        $response = $this->getJson('/probe/write')->assertOk();

        $response->assertJsonPath('all_writes_refused', true);
        $response->assertJsonPath('attempts.insert.refused', true);
        $response->assertJsonPath('attempts.eloquent.refused', true);
        $response->assertJsonPath('attempts.ddl.refused', true);
        $response->assertJsonPath('attempts.delete.refused', true);
        $response->assertJsonPath('rows_after', Post::query()->count());
    }

    public function test_the_version_probe_reports_the_served_content(): void
    {
        $this->getJson('/probe/version')
            ->assertOk()
            ->assertJsonPath('posts', Post::query()->count())
            ->assertJsonPath('latest', Post::published()->value('slug'));
    }
}

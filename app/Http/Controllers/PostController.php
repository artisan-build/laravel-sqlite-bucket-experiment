<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\Post;
use App\Content\PostSearch;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PostController extends Controller
{
    public function index(): View
    {
        return view('posts.index', [
            'posts' => Post::published()->get(),
        ]);
    }

    public function show(string $slug): View
    {
        abort_unless($post = Post::findBySlug($slug), 404);

        return view('posts.show', ['post' => $post]);
    }

    public function search(Request $request, PostSearch $search): View
    {
        $terms = (string) $request->query('q', '');

        return view('posts.search', [
            'terms' => $terms,
            'results' => $search->search($terms),
            'fullText' => $search->enabled(),
        ]);
    }
}

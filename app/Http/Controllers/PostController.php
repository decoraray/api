<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Http\Resources\PostListResource;
use App\Http\Resources\PostShowResource;


class PostController extends Controller
{
    public function index()
    {
        return PostListResource::collection(Post::published()->get());
    }

    public function show($slug, $categorySlug = null)
    {
        $query = Post::published()->where('slug', $slug)->byCategory($categorySlug);

        $post = $query->first();

        if (!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        return new PostShowResource($post);
    }
}

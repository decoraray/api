<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Http\Resources\PostListResource;
use App\Http\Resources\PostShowResource;


class PostController extends Controller
{
    public function index()
    {
        return PostListResource::collection(Post::all());
    }

    public function show($slug, $categorySlug = null)
    {
        // Start with the query for the post by slug
        $query = Post::where('slug', $slug);

        // If categorySlug is provided, filter by the category as well
        if ($categorySlug) {
            $query->whereHas('category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        } else {
            // Filter posts that do not have any category
            $query->whereDoesntHave('category');
        }

        $post = $query->first();

        if (!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        return new PostShowResource($post);
    }
}

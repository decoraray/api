<?php

namespace App\Http\Controllers\Admin;

use App\Models\Post;
use App\Http\Controllers\Controller;
use App\Http\Resources\PostListResource;
use App\Http\Resources\PostShowResource;
use App\Http\Requests\Admin\CreatePostRequest;
use App\Http\Requests\Admin\UpdatePostRequest;


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

    public function store(CreatePostRequest $request)
    {
        $post = new Post($request->all());
        $post->save();

        return new PostShowResource($post);
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $post->update($request->all());
        return new PostShowResource($post);
    }

    public function destroy(Post $post)
    {
        $post->delete();
        return response()->json(null, 204);
    }
}

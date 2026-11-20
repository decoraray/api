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

    public function show($id, $categorySlug = null)
    {
        $query = Post::where('id', $id)->byCategory($categorySlug);

        $post = $query->first();

        if (!$post) {
            return response()->json(['error' => 'Post not found'], 404);
        }

        return new PostShowResource($post);
    }

    public function store(CreatePostRequest $request)
    {
        $post = new Post($request->safe()->except(['images', 'categories']));
        $post->subtitle = (string) ($request->input('subtitle') ?? '');
        $post->save();

        $post->categories()->sync($request->input('categories', []));
        $this->storeCover($request, $post);

        return (new PostShowResource($post->fresh('categories')))->response()->setStatusCode(201);
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $post->update($request->safe()->except(['images', 'categories']));

        if ($request->has('categories')) {
            $post->categories()->sync($request->input('categories') ?? []);
        }
        $this->storeCover($request, $post);

        return new PostShowResource($post->fresh('categories'));
    }

    /** The first uploaded file becomes the post's cover image (posts have one image). */
    private function storeCover($request, Post $post): void
    {
        $file = collect($request->file('images') ?? [])->first();
        if (!$file) {
            return;
        }

        $old = $post->image;
        $post->image = $file->store('image', 'public');
        $post->save();
        if ($old && $old !== $post->image) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
        }
    }

    public function destroy(Post $post)
    {
        $image = $post->image;
        $post->delete();
        if ($image) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($image);
        }

        return response()->json(null, 204);
    }
}

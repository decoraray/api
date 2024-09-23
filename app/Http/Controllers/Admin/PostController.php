<?php

namespace App\Http\Controllers\Admin;

use App\Models\Post;
use App\Models\Image;
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
        $query = Post::where('slug', $slug)->byCategory($categorySlug);

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

        $post->categories()->attach($request->categories);

        if (request()->hasFile('images')) {
            return response()->json([
                'message' => 'No file was uploaded.'
            ], 422);
        }

        $images = $request->file('images');
        if ($images != null) {
            foreach ($images as $image) {
                $image_path = $image->store('image', 'public');
                Image::create([
                    'image' => $image_path,
                    'post_id' => $request->product_id ?? null,
                ]);
                $post->image = $image_path;
                $post->save();

                break; // TODO : it will end loop on first image, if post can support multiple images it will change 
            }
        }

        return new PostShowResource($post);
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $post->update($request->all());

        if ($request->categories) {
            // if categoryes are array get all , else plunk ids and get them
            $categories = is_array($request->categories) ? $request->categories : $request->categories->pluck('id');
            $post->categories()->sync($categories);
        }

        $images = $request->file('images');
        if ($images != null) {
            foreach ($images as $image) {
                $image_path = $image->store('image', 'public');
                Image::create([
                    'image' => $image_path,
                    'post_id' => $request->product_id ?? null,
                ]);
                $post->image = $image_path;
                $post->save();

                break; // TODO : it will end loop on first image, if post can support multiple images it will change 
            }
        }

        return new PostShowResource($post);
    }

    public function destroy(Post $post)
    {
        $post->delete();

        // TODO : delete image too , 

        return response()->json(null, 204);
    }
}

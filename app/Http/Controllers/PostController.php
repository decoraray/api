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

    public function show($slug)
    {
        return new PostShowResource(Post::where('slug', $slug)->first());
    }
}

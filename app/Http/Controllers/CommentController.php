<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Requests\CommentRequest;
use App\Http\Resources\CommentShowResource;

class CommentController extends Controller
{
    public function index(Request $request)
    {
        if (!$request->product_id) {
            return response()->json(['message' => 'Product id is required'], 422);
        }

        $product = Product::find($request->product_id);
        return CommentShowResource::collection($product->comments)->response();
    }

    public function store(CommentRequest $request)
    {
        $product = Product::find($request->getProductId());

        // check if the user has already commented on the product
        if ($product->comments()->where('user_id', auth()->user()->id)->exists()) {
            return response()->json(['message' => 'You have already commented on this product'], 400);
        }

        Comment::create([
            'content' => $request->content,
            'status' => 'pending',
            'image' => $request->image,
            'Product_id' => $request->getProductId(),
            'user_id' => auth()->user()->id,
        ]);
        return response()->json(['message' => 'Comment created successfully'], 201);
    }

    public function destroy(Comment $comment)
    {
        if ($comment->user_id !== auth()->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $comment->delete();
        return response()->json(['message' => 'Comment deleted successfully'], 200);
    }
}

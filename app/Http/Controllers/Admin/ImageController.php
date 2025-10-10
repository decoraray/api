<?php

namespace App\Http\Controllers\Admin;

use App\Models\Post;
use App\Models\Image;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Requests\Admin\UploadImageRequest;

class ImageController extends Controller
{
    public function imageStore(UploadImageRequest $request)
    {
        // Handle both 'images' and 'images[]' field names
        $images = $request->file('images') ?? $request->file('images[]');

        // Get the maximum sort value for this product
        $maxSort = 0;
        if ($request->product_id) {
            $maxSort = Image::where('product_id', $request->product_id)->max('sort') ?? 0;
        }

        foreach ($images as $image) {
            $maxSort++;
            $image_path = $image->store('image', 'public');

            Image::create([
                'image' => $image_path,
                'product_id' => $request->product_id ?? null,
                'sort' => $maxSort,
            ]);
        }

        // if category_id is set, update category image
        if ($request->category_id) {
            $category = Category::find($request->category_id);
            $category->image = $image_path;
            $category->save();
        }

        if ($request->post_id) {
            $post = Post::find($request->post_id);
            $post->image = $image_path;
            $post->save();
        }

        return response(Response::HTTP_CREATED);
    }

    // imageDelete
    public function imageDelete(Request $request)
    {
        // find image in database by address (image_path)
        $path = explode('storage/', $request->address)[1];

        $image = Image::where('image', $path)->first();

        if (!$image) {
            return response()->json([
                'message' => 'Image not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $image->delete();

        return response(Response::HTTP_NO_CONTENT);
    }

    public function image(Image $image)
    {
        $url = asset('storage/' . $image->image_path);

        return response()->json([
            'url' => $url
        ]);
    }

    public function reorderImages(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'images' => 'required|array',
            'images.*' => 'required|string', // Image URLs
        ]);

        $images = Image::where('product_id', $request->product_id)->get();

        foreach ($request->images as $index => $imageUrl) {
            // Extract path from URL
            $path = explode('storage/', $imageUrl)[1] ?? null;

            if ($path) {
                $image = $images->firstWhere('image', $path);
                if ($image) {
                    $image->sort = $index + 1;
                    $image->save();
                }
            }
        }

        return response()->json([
            'message' => 'Images reordered successfully'
        ], Response::HTTP_OK);
    }
}

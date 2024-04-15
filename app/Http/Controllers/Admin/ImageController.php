<?php

namespace App\Http\Controllers\Admin;

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

        $images = $request->file('images');

        foreach ($images as $image) {

            $image_path = $image->store('image', 'public');

            Image::create([
                'image' => $image_path,
                'product_id' => $request->product_id ?? null,
            ]);
        }

        // if category_id is set, update category image
        if ($request->category_id) {
            $category = Category::find($request->category_id);
            $category->image = $image_path;
            $category->save();
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
}

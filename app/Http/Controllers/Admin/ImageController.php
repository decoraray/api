<?php

namespace App\Http\Controllers\Admin;

use App\Models\Image;
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
                'product_id' => $request->product_id
            ]);
        }

        return response(Response::HTTP_CREATED);
    }

    public function image(Image $image)
    {
        $url = asset('storage/' . $image->image_path);

        return response()->json([
            'url' => $url
        ]);
    }
}

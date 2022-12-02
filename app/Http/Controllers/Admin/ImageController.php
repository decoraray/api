<?php

namespace App\Http\Controllers\Admin;

use App\Models\Image;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Requests\Admin\UploadImageRequest;

class ImageController extends Controller
{
    public function imageStore(UploadImageRequest $request)
    {

        $image_path = $request->file('image')->store('image', 'public');

        $data = Image::create([
            'image' => $image_path,
            'product_id' => $request->product_id
        ]);

        return response($data, Response::HTTP_CREATED);
    }

    public function show(Image $image)
    {
        return response()->file(storage_path('/storage/' . $image->image_path));
    }
}

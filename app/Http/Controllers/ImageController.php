<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Requests\ImageRequest;
use Intervention\Image\Laravel\Facades\Image;

class ImageController extends Controller
{
    public function get(ImageRequest $request)
    {

        // read image
        $image = Image::read('storage/' . $request->path);

        // if file not found
        if (!$image) {
            return response()->json([
                'status' => false,
                'message' => 'File not found',
            ], 404);
        }

        // change name
        if ($request->slug) {
            $image->filename = $request->slug . '-' . time() . '.webp';
        }

        // set quality
        if ($request->quality) {
            $image->encodeByMediaType('image/webp', (int) $request->quality);
        }

        // Resize image
        if ($request->has('width')) {
            // Only width is provided
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            // Calculate the aspect ratio
            $aspectRatio = $originalWidth / $originalHeight;

            // Calculate the new height if width is provided
            $newWidth = (int)$request->width;
            $newHeight = $request->has('height') ? (int)$request->height : null;

            if ($newHeight === null) {
                // Calculate the height based on the aspect ratio
                $newHeight = round($newWidth / $aspectRatio);
            }

            // Resize the image
            $image->resize($newWidth, $newHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        // save to temp file
        $tempImagePath = tempnam(sys_get_temp_dir(), 'image_');
        $image->save($tempImagePath);

        // create response
        $response = new Response();
        $response->headers->set('Content-Type', 'image/webp');
        $response->setContent(file_get_contents($tempImagePath));

        // remove temp file
        unlink($tempImagePath);

        return $response;
    }
}

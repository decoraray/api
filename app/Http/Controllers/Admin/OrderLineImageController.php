<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItemImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Reference images of order lines: upload first, attach by id when the order is saved. */
class OrderLineImageController extends Controller
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const TYPE_MESSAGE = 'فقط تصویر (JPG، PNG، WebP) قابل بارگذاری است.';

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'file.required' => 'تصویری انتخاب نشده است.',
            'file.mimes' => self::TYPE_MESSAGE,
            'file.max' => 'حجم تصویر نباید بیشتر از ۵ مگابایت باشد.',
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $ext = self::EXTENSIONS[$mime] ?? null;
        if (!$ext) {
            abort(422, self::TYPE_MESSAGE);
        }

        $path = $file->storeAs(now()->format('Y/m'), Str::uuid() . '.' . $ext, 'order-line-images');
        abort_if($path === false, 500, 'ذخیره تصویر انجام نشد.');

        $image = OrderItemImage::create([
            'disk' => 'order-line-images',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $mime,
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $image->toPayload()], 201);
    }

    /** Reached by signed link only (no JWT), so <img src> works in the admin. */
    public function file(OrderItemImage $image)
    {
        $disk = Storage::disk($image->disk);
        abort_unless($disk->exists($image->path), 404);

        return $disk->response($image->path, null, [
            'Content-Type' => $image->mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}

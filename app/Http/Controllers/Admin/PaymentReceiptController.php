<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Receipt images/PDFs for staff-recorded payments: upload first, attach by id when the payment is saved. */
class PaymentReceiptController extends Controller
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'file.mimes' => 'فقط تصویر (JPG، PNG، WebP) یا PDF قابل بارگذاری است.',
            'file.max' => 'حجم فایل رسید نباید بیشتر از ۵ مگابایت باشد.',
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $ext = self::EXTENSIONS[$mime] ?? null;
        if (!$ext) {
            abort(422, 'فقط تصویر (JPG، PNG، WebP) یا PDF قابل بارگذاری است.');
        }

        $path = $file->storeAs(now()->format('Y/m'), Str::uuid() . '.' . $ext, 'receipts');

        $attachment = PaymentAttachment::create([
            'disk' => 'receipts',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $mime,
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $attachment->toReceipt()], 201);
    }

    /** Reached by signed link only (no JWT), so <img src> works in the admin. */
    public function file(PaymentAttachment $attachment)
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, null, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}

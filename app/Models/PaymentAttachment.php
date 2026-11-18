<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** A receipt file (image or PDF) for a staff-recorded payment. Stored on a private disk. */
class PaymentAttachment extends Model
{
    use HasFactory;

    public const LINK_MINUTES = 30;

    protected $fillable = [
        'payment_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected static function booted(): void
    {
        static::deleting(function (PaymentAttachment $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Short-lived link that works without the JWT (for <img src>). The signature is relative so it
     * survives scheme/host differences behind the CDN and nginx.
     */
    public function signedUrl(): string
    {
        $path = URL::temporarySignedRoute(
            'payment-receipt-file',
            now()->addMinutes(self::LINK_MINUTES),
            ['attachment' => $this->id],
            false
        );

        return rtrim(config('app.url'), '/') . $path;
    }

    /** Shape used by admin resources. */
    public function toReceipt(): array
    {
        return [
            'id' => $this->id,
            'mime' => $this->mime,
            'name' => $this->original_name,
            'url' => $this->signedUrl(),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/** A reference photo or sketch of an order line, shown to staff only. Stored on a private disk. */
class OrderItemImage extends Model
{
    use HasFactory;

    public const LINK_MINUTES = 30;

    public const MAX_PER_LINE = 5;

    protected $fillable = [
        'item_id',
        'position',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected static function booted(): void
    {
        static::deleting(function (OrderItemImage $image) {
            Storage::disk($image->disk)->delete($image->path);
        });
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    /** Short-lived link that works without the JWT (for <img src>); relative signature, like receipts. */
    public function signedUrl(): string
    {
        $path = URL::temporarySignedRoute(
            'order-line-image-file',
            now()->addMinutes(self::LINK_MINUTES),
            ['image' => $this->id],
            false
        );

        return rtrim(config('app.url'), '/') . $path;
    }

    /** Shape used by admin resources. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'mime' => $this->mime,
            'name' => $this->original_name,
            'url' => $this->signedUrl(),
        ];
    }
}

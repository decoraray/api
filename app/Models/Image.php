<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Image extends Model
{
    use HasFactory;

    protected $fillable = [
        'image',
        'product_id'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getImagePathAttribute()
    {
        return 'https://decoraray-api.mamoyz.dev/storage/' . $this->image;
        // return asset('storage/' . $this->image);
    }

    // delete the image from the storage
    public function delete()
    {
        Storage::disk('public')->delete($this->image);
        Storage::delete($this->image);

        parent::delete();
    }
}

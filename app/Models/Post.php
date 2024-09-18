<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'image',
        'video',
        'content',
        'writer',
        'status',
        'category_id'
    ];

    public function getImagePathAttribute()
    {
        return $this->image ? config('app.url') . '/storage/' . $this->image : null;
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function scopeByCategory($query, $category)
    {
        if (!$category || $category == 'null') {
            return;
        }

        $query->whereHas('categories', function ($query) use ($category) {
            $query->where('category_id', $category)
                ->orWhere('slug', 'like', "%$category%");
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Post extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'image',
        'video',
        'content',
        'writer',
        'status'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function getImagePathAttribute()
    {
        return $this->image ? config('app.url') . '/storage/' . $this->image : null;
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    /** Shown on the storefront: published, or never given a status (older posts). */
    public function scopePublished($query)
    {
        $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', true));
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

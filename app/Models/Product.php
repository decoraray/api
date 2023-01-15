<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'category_id',
        'active',
        'code',
        'slug',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function carts()
    {
        return $this->belongsToMany(Cart::class)
            ->withPivot('quantity', 'description')
            ->withTimestamps();
    }

    /**
     * Scope a query to only include active users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeActive($query)
    {
        $query->where('active', 1);
    }

    public function rating()
    {
        return $this->hasOne(Rating::class);
    }

    public function images()
    {
        return $this->hasMany(Image::class);
    }

    public function getThumbnailAttribute()
    {
        return $this->hasMany(Image::class)->orderBy('id', 'desc')->first();
    }

    public function metas()
    {
        return $this->hasMany(ProductMeta::class);
    }

    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    public function variations()
    {
        return $this->hasMany(Variation::class);
    }

    public function getAveragePriceAttribute()
    {
        return $this->variations->avg('price');
    }

    public function getColorsAttribute()
    {
        return $this->variations->pluck('color')->unique();
    }
}

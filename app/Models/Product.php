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
        'tag',
        'description',
        'active',
        'code',
        'slug',
        'dimensions',
        'average_price',
    ];

    public function categories()
    {
        return $this->belongsToMany(Category::class);
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

    public function scopeHasActiveVariation($query)
    {
        $query->whereHas('variations', function ($query) {
            $query->where('active', 1);
        });
    }

    public function rating()
    {
        return $this->hasOne(Rating::class);
    }

    // relations
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

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }

    // attributes
    public function getAveragePriceAttribute()
    {
        return round($this->variations->avg('price'));
    }

    public function getPriceAttribute()
    {
        return $this->variations->min('price');
    }

    public function getAvailabilityAttribute()
    {
        return $this->variations->sum('stock') > 0 ? 'instock' : 'out of stock';
    }

    public function updateAveragePrice()
    {
        $this->average_price = round($this->variations->avg('price'), 1);
        $this->save();
    }

    public function getColorsAttribute()
    {
        return $this->variations->pluck('color')->unique();
    }

    // scopes
    public function scopePopular($query)
    {
        $query->where('active', 1)->orderBy('id', 'desc');
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

    public function scopeByTag($query, $tag)
    {
        if (!$tag) {
            return;
        }

        $query->where('tag_id', 'like', $tag);
    }

    public function scopeSort($query)
    {
        if (request()->has('sort')) {
            if (request()->get('sort') == 'price_asc') {
                $query->priceAsc();
            } elseif (request()->get('sort') == 'price_desc') {
                $query->priceDesc();
            } elseif (request()->get('sort') == 'newest') {
                $query->newest();
            } elseif (request()->get('sort') == 'oldest') {
                $query->oldest();
            };
        }
    }

    public function scopePriceAsc($query)
    {
        $query->orderBy('average_price', 'asc');
    }

    public function scopePriceDesc($query)
    {
        $query->orderBy('average_price', 'desc');
    }

    public function scopeNewest($query)
    {
        $query->orderBy('id', 'desc');
    }

    public function scopeOldest($query)
    {
        $query->orderBy('id', 'asc');
    }
}

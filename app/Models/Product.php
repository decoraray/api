<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;


class Product extends Model
{
    use HasFactory;
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'slug',
        'search_title',
        'tag',
        'description',
        'active',
        'code',
        'dimensions',
        'average_price',
        'thumbnail_id',
    ];


    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray()
    {
        return array_merge($this->toArray(), [
            'id' => (string) $this->id,
            'search_title' => $this->search_title,
            'created_at' => $this->created_at->timestamp,
        ]);
    }

    /**
     * Set the search_title attribute whenever the title is set or updated.
     *
     * @param  string  $value
     * @return void
     */
    public function setTitleAttribute($value)
    {
        $this->attributes['title'] = $value;

        $spaceLikeChars = [' ', '‌', "\t", "\n", "\r", "\0", "\x0B"]; // Normal space and ZWNJ (Zero Width Non-Joiner)

        $this->attributes['search_title'] = strtolower(str_replace($spaceLikeChars, '', $value)); // Remove spaces for the search_title
    }

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

        if ($this->thumbnail_id) {
            return Image::find($this->thumbnail_id);
        }

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

    public function deal()
    {
        return $this->belongsTo(Deal::class)->where('active', 1);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    // attributes
    public function getOldAveragePriceAttribute()
    {
        return round($this->variations->avg('price'));
    }

    public function getOldPriceAttribute()
    {
        return $this->variations->min('oldPrice');
    }

    public function getAveragePriceAttribute()
    {
        $discount = 0;
        if ($this->deal) {
            $discount = $this->oldAveragePrice * $this->deal->discount / 100;
        }
        return $this->oldAveragePrice - $discount;
    }

    public function getPriceAttribute()
    {
        $discount = 0;
        if ($this->deal) {
            $discount = $this->oldAveragePrice * $this->deal->discount / 100;
        }
        return $this->oldPrice - $discount;
    }

    public function getAvailabilityAttribute()
    {
        return $this->variations->sum('stock') > 0 ? 'instock' : 'outofstock';
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


    /**
     * The method will look up the sort parameter from the request. If it matches one of the keys in $sortOptions,
     * it will call the corresponding scope method (priceAsc, priceDesc, newest, or oldest) on the query.
     */
    public function scopeSort($query)
    {
        $sortOptions = [
            'price_asc' => 'priceAsc',
            'price_desc' => 'priceDesc',
            'newest' => 'newest',
            'oldest' => 'oldest',
            'by_title' => 'byTitle',
        ];

        $sort = request()->get('sort', 'newest'); // Default to 'newest' if no sort parameter provided

        if (array_key_exists($sort, $sortOptions)) {
            $query->{$sortOptions[$sort]}();
        } else {
            $query->newest(); // Default to sorting by newest
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

    public function scopeByTitle($query)
    {
        $query->orderBy('title', 'asc');
    }

    // relatedProducts
    public function getRelatedProductsAttribute()
    {
        // get 10 randome from same category and tag and not the same product

        return Product::active()
            ->byCategory($this->categories->first()->id)
            ->byTag($this->tag_id)
            ->where('id', '<>', $this->id)
            ->inRandomOrder()
            ->limit(10)
            ->get();
    }
}

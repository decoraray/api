<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Variation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'color_id',
        'material_id',
        'size',
        'stock',
        'weight',
        'price',
        'active',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function colors()
    {
        return $this->belongsToMany(Color::class, 'color_variation');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    // get oldPrice attribute
    public function getOldPriceAttribute()
    {
        return $this->price;
    }

    // get calculatedPrice attribute
    public function getCalculatedPriceAttribute()
    {
        $discount = 0;
        if ($this->product->deal) {
            $discount = $this->price * $this->product->deal->discount / 100;
        }
        return $this->price - $discount;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'discount_id',
        'user_id'
    ];

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('quantity', 'description')
            ->withTimestamps();
    }

    public function productsIds()
    {
        return empty($this->products->items) ? [] : $this->products()->pluck('product_id')->toArray();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // merge another carts products in this
    public function merge(Cart $firstCart)
    {
        $firstCart->products()->each(function ($product) {
            $this->add($product);
        });

        $firstCart->delete();

        $this->save();
    }

    private function add($product)
    {
        if ($this->products->contains($product)) {
            $this->products()->updateExistingPivot($product, [
                'quantity' => $this->products()->find($product)->pivot->quantity + $product->pivot->quantity,
                'description' => $product->pivot->description ?? '',
            ]);
        } else {
            $this->products()->attach($product, [
                'quantity' => $product->pivot->quantity,
                'description' => $product->pivot->description ?? '',
            ]);
        }
    }

    public function total()
    {
        $total = 0;
        for ($i = 0; $i < count($this->productsIds()); $i++) {
            $total += $this->products()->find($this->productsIds()[$i])->pivot->quantity
                * $this->products()->find($this->productsIds()[$i])->price;
        }
        return $total;
    }

    public function totalQuantity()
    {
        $total = 0;
        for ($i = 0; $i < count($this->productsIds()); $i++) {
            $total += $this->products()->find($this->productsIds()[$i])->pivot->quantity;
        }
        return $total;
    }

    public function clear()
    {
        $this->products()->detach();
    }

    public function isEmpty()
    {
        return $this->products()->count() == 0;
    }

    // get total attribute

    public function getTotalAttribute()
    {
        return $this->total();
    }

    public function getTotalQuantityAttribute()
    {
        return $this->totalQuantity();
    }

    public function getIsEmptyAttribute()
    {
        return $this->isEmpty();
    }

    public function getDiscountAttribute()
    {
        return $this->discount()->exists() ? $this->discount->percent : 0;
    }

    public function getDiscountAmountAttribute()
    {
        return $this->discount()->exists() ? $this->total() * $this->discount->percent / 100 : 0;
    }

    public static function getOrCreate(?string $uuid = null)
    {
        if (auth()->check()) {
            return Cart::firstOrCreate([
                'user_id' => auth()->id()
            ]);
        } else {
            return Cart::firstOrCreate([
                'uuid' => $uuid ?? uniqid(),
            ]);
        }
    }

    public function count()
    {
        return $this->products()->count();
    }
}

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
        'user_id'
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('quantity','description')
            ->withTimestamps();
    }

    public function productsIds()
    {
        return $this->products()->pluck('product_id')->toArray();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // merge another carts products in this
    public function merge(Cart $cart)
    {
        $cart->products()->each(function ($product) {
            $this->add($product);
        });
        $cart->delete();
    }

    private function add($product)
    {
        if ($this->products->contains($product)) {
            $this->products()->updateExistingPivot($product, [
                'quantity' => $this->products()->find($product)->pivot->quantity + $product->pivot->quantity ,
                'description' => $product->pivot->description??'',
            ]);
        } else {
            $this->products()->attach($product, [
                'quantity' => $product->pivot->quantity,
                'description' => $product->pivot->description??'',
            ]);
        }
    }

    public function total()
    {
        $total = 0;
        for ($i=0; $i < count($this->productsIds()); $i++) {

            $total += $this->products()->find($this->productsIds()[$i])->pivot->quantity
                * $this->products()->find($this->productsIds()[$i])->price;

        }
        return $total;
    }

}

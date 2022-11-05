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

}

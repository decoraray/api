<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'rating',
        'count',
        'product_id',
    ];

    function product()
    {
        return $this->belongsTo(Product::class);
    }

    function rate(float $rating)
    {
        $this->count = $this->count + 1;
        $this->rating = $this->rating + $rating;
        $this->save();
    }

}

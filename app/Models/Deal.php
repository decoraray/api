<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'description',
        'active',
        'discount',
        'start_date',
        'end_date',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}

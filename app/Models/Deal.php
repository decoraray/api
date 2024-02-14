<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


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

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive($query)
    {
        $query->where('active', 1);
    }
}

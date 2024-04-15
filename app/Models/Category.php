<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'image',
        'parent_id',
        'description',
    ];

    public function discount()
    {
        return $this->hasOne(Discount::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class);
    }

    public function scopeParents($query)
    {
        return $query->whereNull('parent_id');
    }

    public function getImagePathAttribute()
    {
        return $this->image ? config('app.url') . '/storage/' . $this->image : null;
    }
}

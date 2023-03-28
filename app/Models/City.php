<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasFactory;

    public function parent()
    {
        return $this->belongsTo(City::class, 'parent');
    }

    public function children()
    {
        return $this->hasMany(City::class, 'parent');
    }

    public function shippings()
    {
        return $this->belongsToMany(Shipping::class, 'city_shipping')->withPivot('override_price');;
    }
}

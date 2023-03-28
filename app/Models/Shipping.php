<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shipping extends Model
{
    use HasFactory;

    public function orders()
    {
        return $this->belongsToMany(Order::class);
    }

    public function cities()
    {
        return $this->belongsToMany(City::class, 'city_shipping')->withPivot('override_price');
    }
}

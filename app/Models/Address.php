<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'name',
        'phone',
        'address',
        'city',
        'county',
        'postal_code',
        'city_id',
        'user_id',
        'uuid',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // dor payment availability based on city
    public function getDoorPaymentAttribute()
    {
        $city = City::where('id', $this->city_id)->first();
        return $city->door_payment;
    }

    public function getShippingAttribute()
    {
        $city = City::where('id', $this->city_id)->first();
        return $city->shipping;
    }

    // return empty string if postal code is null
    public function getPostalCodeAttribute($value)
    {
        return $value ?? '';
    }
}

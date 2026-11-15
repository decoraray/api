<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNotification extends Model
{
    protected $fillable = ['order_id', 'event', 'phone', 'status', 'error'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}

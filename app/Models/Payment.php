<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    function __construct()
    {
        //
    }

    protected $fillable = [
        'uuid',
        'amount',
        'currency',
        'description',
        'user_phone',
        'method',
        'user_id',
        'order_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->belongsTo(Order::class);
    }

    public function pay()
    {
        PaymentService::pay($this);
        $this->status = 'paid';
        $this->save();
    }
}

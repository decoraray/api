<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What arrived from a purchase order on one delivery. Its stock movements reference it. */
class GoodsReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'note', 'received_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}

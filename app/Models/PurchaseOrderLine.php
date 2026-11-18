<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'stock_item_id', 'quantity', 'unit_price', 'received_quantity'];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'integer',
        'received_quantity' => 'float',
    ];

    protected $attributes = ['received_quantity' => 0];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function requests()
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function getOutstandingAttribute(): float
    {
        return max(0.0, round($this->quantity - $this->received_quantity, 3));
    }
}

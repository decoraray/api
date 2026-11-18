<?php

namespace App\Models;

use App\Enums\PurchaseRequestState;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequest extends Model
{
    protected $fillable = ['stock_item_id', 'quantity', 'note', 'state', 'requested_by', 'purchase_order_line_id'];

    protected $casts = [
        'quantity' => 'float',
        'state' => PurchaseRequestState::class,
    ];

    protected $attributes = ['state' => 'open'];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function orderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}

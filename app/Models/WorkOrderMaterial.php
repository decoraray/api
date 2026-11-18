<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One material a work order needs: what was planned, what is held for it, what it used and wasted. */
class WorkOrderMaterial extends Model
{
    protected $fillable = ['work_order_id', 'stock_item_id', 'planned_quantity', 'reserved_quantity', 'consumed_quantity', 'wasted_quantity', 'note'];

    protected $casts = [
        'planned_quantity' => 'float',
        'reserved_quantity' => 'float',
        'consumed_quantity' => 'float',
        'wasted_quantity' => 'float',
    ];

    protected $attributes = [
        'planned_quantity' => 0,
        'reserved_quantity' => 0,
        'consumed_quantity' => 0,
        'wasted_quantity' => 0,
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    /** What the job still needs that is neither held for it nor already used. */
    public function getUnreservedNeedAttribute(): float
    {
        return max(0.0, round($this->planned_quantity - $this->reserved_quantity - $this->consumed_quantity, 3));
    }
}

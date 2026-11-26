<?php

namespace App\Models;

use App\Enums\CuttingOp;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;

/** One part of a work order's own cutting list, per unit. Copied from its design, then editable by the workshop. */
class WorkOrderPart extends Model
{
    protected $fillable = [
        'work_order_id', 'position', 'name', 'length_mm', 'width_mm', 'count',
        'stock_item_id', 'edge_length', 'edge_width', 'grain_locked', 'ops', 'note',
    ];

    protected $casts = [
        'length_mm' => 'integer',
        'width_mm' => 'integer',
        'count' => 'integer',
        'edge_length' => 'integer',
        'edge_width' => 'integer',
        'grain_locked' => 'boolean',
        'ops' => AsEnumCollection::class . ':' . CuttingOp::class,
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** The MDF sheet stock item the part is cut from. */
    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}

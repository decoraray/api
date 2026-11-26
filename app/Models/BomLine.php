<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One material of a design's bill of materials: the quantity one unit needs. */
class BomLine extends Model
{
    // calculated_quantity: the part of `quantity` that follows from the design's parts (sheets and edge band).
    protected $fillable = ['design_id', 'stock_item_id', 'quantity', 'calculated_quantity', 'note'];

    protected $casts = ['quantity' => 'float', 'calculated_quantity' => 'float'];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }
}

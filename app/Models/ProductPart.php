<?php

namespace App\Models;

use App\Enums\CuttingOp;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One wooden part of a design's cutting list, for one unit. Dimensions are as written on the
 * workshop drawing; edge_length/edge_width are the number of lines drawn under each number.
 */
class ProductPart extends Model
{
    use HasFactory;

    protected $fillable = [
        'design_id', 'position', 'name', 'length_mm', 'width_mm', 'count',
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

    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }

    /** The MDF sheet stock item the part is cut from. */
    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}

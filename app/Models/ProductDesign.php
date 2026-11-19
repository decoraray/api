<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named way of building a product («طرح ساخت»): its own cutting list, BOM and panel mapping.
 * A product can have any number of designs; one is the product's default and a variation can have its own default.
 */
class ProductDesign extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'product_id', 'variation_id', 'name', 'dimensions', 'note', 'is_default', 'active',
        'panel_map', 'source_work_order_id', 'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
        'panel_map' => 'array',
    ];

    protected $attributes = [
        'is_default' => false,
        'active' => true,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'dimensions', 'note', 'is_default', 'variation_id', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }

    public function parts()
    {
        return $this->hasMany(ProductPart::class, 'design_id')->orderBy('position')->orderBy('id');
    }

    public function bomLines()
    {
        return $this->hasMany(BomLine::class, 'design_id')->orderBy('id');
    }

    public function sourceWorkOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'source_work_order_id');
    }
}

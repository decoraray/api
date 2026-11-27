<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'price',
        'discount',
        'product_id',
        'variation_id',
        'order_id',
        'quantity',
        'dimensions',
        'color',
        'color_id',
        'design_id',
    ];

    /** The colour chosen from the Colours list; a label only. `color` is the free text kept beside it. */
    public function colour()
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function workOrder()
    {
        return $this->hasOne(WorkOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }

    /** Reference photos/sketches attached by staff. */
    public function images()
    {
        return $this->hasMany(OrderItemImage::class)->orderBy('position')->orderBy('id');
    }

    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }
}

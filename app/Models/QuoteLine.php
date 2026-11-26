<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteLine extends Model
{
    protected $fillable = [
        'quote_id', 'position', 'product_id', 'variation_id', 'description',
        'dimensions', 'color', 'color_id', 'quantity', 'unit_price', 'design_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'integer',
    ];

    /** The colour chosen from the Colours list; a label only. `color` is the free text kept beside it. */
    public function colour()
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}

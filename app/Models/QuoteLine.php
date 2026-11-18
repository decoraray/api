<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuoteLine extends Model
{
    protected $fillable = [
        'quote_id', 'position', 'product_id', 'variation_id', 'description',
        'dimensions', 'color', 'quantity', 'unit_price', 'design_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'integer',
    ];

    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}

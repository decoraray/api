<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\WholesalePricing;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Variation extends Model
{
    use HasFactory;
    use LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'color_id',
        'material_id',
        'design_id',
        'size',
        'stock',
        'weight',
        'price',
        'wholesale_price',
        'active',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    public function colors()
    {
        return $this->belongsToMany(Color::class, 'color_variation');
    }

    /** The design the variation is built from; null means the product's default design. */
    public function design()
    {
        return $this->belongsTo(ProductDesign::class, 'design_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    // get oldPrice attribute
    public function getOldPriceAttribute()
    {
        return $this->price;
    }

    // get calculatedPrice attribute
    public function getCalculatedPriceAttribute()
    {
        $discount = 0;
        if ($this->product->deal) {
            $discount = $this->price * $this->product->deal->discount / 100;
        }
        return $this->price - $discount;
    }

    /** Own wholesale price, else retail (before deals) minus the default percentage. Deals never apply. */
    public function effectiveWholesalePrice(): int
    {
        if ($this->wholesale_price !== null) {
            return (int) $this->wholesale_price;
        }

        return WholesalePricing::fallback((int) $this->price);
    }
}

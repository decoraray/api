<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One material of a product's bill of materials: the quantity one unit needs. */
class BomLine extends Model
{
    protected $fillable = ['product_id', 'variation_id', 'stock_item_id', 'quantity', 'note'];

    protected $casts = ['quantity' => 'float'];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The BOM that applies to a line: the variation's own if it has one, else the product's.
     *
     * @return array{0: \Illuminate\Support\Collection<int, BomLine>, 1: string} lines and their source (variation, product, none)
     */
    public static function resolve(?int $productId, ?int $variationId): array
    {
        if (!$productId) {
            return [collect(), 'none'];
        }

        if ($variationId) {
            $own = static::with('stockItem')->where('product_id', $productId)->where('variation_id', $variationId)->orderBy('id')->get();
            if ($own->isNotEmpty()) {
                return [$own, 'variation'];
            }
        }

        $shared = static::with('stockItem')->where('product_id', $productId)->whereNull('variation_id')->orderBy('id')->get();

        return [$shared, $shared->isEmpty() ? 'none' : 'product'];
    }
}

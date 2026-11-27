<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Variation;
use App\Services\Costing\VariationEstimate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** A variation's cost to build and its suggested price (costs.view), and applying that price. */
class VariationCostController extends Controller
{
    public function __construct(private VariationEstimate $estimate)
    {
    }

    public function show(Variation $variation)
    {
        return response()->json(['data' => $this->estimate->forVariation($variation)]);
    }

    public function product(Product $product)
    {
        $estimates = $this->estimate->forVariations(new Collection($product->variations()->orderBy('id')->get()->all()));

        return response()->json(['data' => array_values($estimates)]);
    }

    /** Sets the price to the current suggestion. The only way a price follows a cost. */
    public function apply(Variation $variation)
    {
        $suggested = $this->estimate->forVariation($variation)['suggested_price'];
        if ($suggested === null) {
            throw ValidationException::withMessages(['price' => ['برای این تنوع قیمت پیشنهادی وجود ندارد؛ طرح ساخت و بهای مواد را بررسی کنید.']]);
        }

        $variation->update(['price' => $suggested]);
        $variation->product?->updateAveragePrice();

        return response()->json(['data' => $this->estimate->forVariation($variation->fresh())]);
    }
}

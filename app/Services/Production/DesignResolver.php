<?php

namespace App\Services\Production;

use App\Models\Item;
use App\Models\ProductDesign;
use Illuminate\Validation\ValidationException;

/**
 * The one place that decides which design an order line is built from: the line's chosen design, else its
 * variation's default design, else its product's default design, else none.
 */
class DesignResolver
{
    public function forLine(?int $designId, ?int $productId, ?int $variationId): ?ProductDesign
    {
        // A chosen design is used even if deactivated later: it was valid when the line was written.
        if ($designId && ($design = ProductDesign::find($designId))) {
            return $design;
        }

        if ($variationId && ($design = ProductDesign::where('variation_id', $variationId)->first())) {
            return $design;
        }

        if ($productId) {
            return ProductDesign::where('product_id', $productId)->where('is_default', true)->first();
        }

        return null;
    }

    /**
     * Checks a design named on an order or quote line: it must be active and, on a line with a product, belong to
     * that product. A custom line may name a design of any product, a one-off product included.
     */
    public function assertAllowed(?int $designId, ?int $productId, string $field): void
    {
        if (!$designId) {
            return;
        }

        $design = ProductDesign::find($designId);
        if (!$design || !$design->active) {
            throw ValidationException::withMessages([$field => ['طرح ساخت انتخاب‌شده وجود ندارد یا غیرفعال است.']]);
        }
        if ($productId && $design->product_id !== $productId) {
            throw ValidationException::withMessages([$field => ['طرح ساخت باید از همان محصول این ردیف باشد.']]);
        }
    }

    public function forItem(?Item $item): ?ProductDesign
    {
        return $item ? $this->forLine($item->design_id, $item->product_id, $item->variation_id) : null;
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductDesign;
use Illuminate\Validation\ValidationException;
use App\Models\Variation;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ProductShowAdminResource;
use App\Http\Resources\Admin\VariationShowAdminResource;

class VariationController extends Controller
{
    public function index()
    {
        $variations = Variation::with(['colors', 'color', 'material', 'design', 'product'])
            ->orderBy('id', 'desc')
            ->paginate(20);

        return VariationShowAdminResource::collection($variations);
    }

    public function show(Variation $variation)
    {
        $variation->load(['colors', 'color', 'material', 'design', 'product']);

        return new VariationShowAdminResource($variation);
    }

    //arrayStore
    public function arrayStore(Request $request, Product $product)
    {
        $variations = $request->all();

        // Validate that we have variations
        if (empty($variations)) {
            return response()->json(['message' => 'No variations provided'], 400);
        }

        // Validate each variation
        foreach ($variations as $index => $variation) {
            if (empty($variation['colors']) || !is_array($variation['colors']) || count($variation['colors']) === 0) {
                return response()->json([
                    'message' => "Variation at index {$index} is missing required fields (colors array)"
                ], 422);
            }
            if (empty($variation['price'])) {
                return response()->json([
                    'message' => "Variation at index {$index} is missing required fields (price)"
                ], 422);
            }
        }

        // Designs are checked before anything is deleted.
        foreach ($variations as $index => $variation) {
            if (is_array($variation) && array_key_exists('design_id', $variation)) {
                $this->designInput($variation['design_id'], $product->id, "{$index}.design_id");
            }
        }

        $canWholesale = (bool) $request->user()?->can('prices.wholesale');

        // Saving replaces the variations, so each keeps its design when the request does not send one.
        $existing = $product->variations()->with('colors')->get();
        $designById = $existing->pluck('design_id', 'id')->all();
        $designByKey = [];
        foreach ($existing as $old) {
            $designByKey[$this->variationKey($old->size, $old->colors->pluck('name')->all(), $old->material_id)] ??= $old->design_id;
        }

        // Without prices.wholesale, carry wholesale prices over to matching variations.
        $preserved = [];
        if (!$canWholesale) {
            foreach ($product->variations()->with('colors')->get() as $old) {
                if ($old->wholesale_price !== null) {
                    $preserved[$this->variationKey($old->size, $old->colors->pluck('name')->all(), $old->material_id)] = $old->wholesale_price;
                }
            }
        }

        // Delete existing variations and create new ones
        $product->variations()->delete();

        foreach ($variations as $variationData) {
            // Normalize material_id and size - convert empty strings to null
            $materialId = !empty($variationData['material_id']) ? $variationData['material_id'] : null;
            $size = !empty($variationData['size']) && trim($variationData['size']) !== '' ? trim($variationData['size']) : null;

            // `variations.color_id` is NOT NULL, so colors must be resolved before create().
            $colorIds = [];
            foreach ($variationData['colors'] as $colorName) {
                $color = Color::findOrCreate($colorName);
                $colorIds[] = $color->id;
            }

            $variation = $product->variations()->create([
                'product_id' => $product->id,
                'color_id' => $colorIds[0], // Keep for backward compatibility, but use colors relationship
                'material_id' => $materialId,
                'design_id' => array_key_exists('design_id', $variationData)
                    ? $this->designInput($variationData['design_id'], $product->id, 'design_id')
                    : ($designById[$variationData['id'] ?? 0] ?? $designByKey[$this->variationKey($size, $variationData['colors'], $materialId)] ?? null),
                'size' => $size,
                'stock' => $variationData['stock'] ?? 0,
                'weight' => $variationData['weight'] ?? null,
                'price' => $variationData['price'],
                'wholesale_price' => $canWholesale
                    ? $this->wholesaleInput($variationData['wholesale_price'] ?? null)
                    : ($preserved[$this->variationKey($size, $variationData['colors'], $materialId)] ?? null),
                'active' => $variationData['active'] ?? true,
            ]);

            $variation->colors()->sync($colorIds);
        }

        // Update product average price
        $product->updateAveragePrice();

        $product->load(['variations.colors', 'variations.color', 'variations.material', 'variations.design']);

        return new ProductShowAdminResource($product);
    }

    public function store(Request $request)
    {
        // Normalize material_id and size - convert empty strings to null
        $materialId = !empty($request->material_id) ? $request->material_id : null;
        $size = !empty($request->size) && trim($request->size) !== '' ? trim($request->size) : null;

        // Resolve colors before the initial insert: `variations.color_id` is NOT NULL,
        // so it must be set on the model before save(), not assigned afterward.
        $colorIds = [];
        $legacyColorId = null;
        if ($request->has('colors') && is_array($request->colors) && count($request->colors) > 0) {
            foreach ($request->colors as $colorName) {
                $color = Color::findOrCreate($colorName);
                $colorIds[] = $color->id;
            }
            $legacyColorId = $colorIds[0];
        } elseif ($request->has('color')) {
            // Backward compatibility: single color as string
            $color = Color::findOrCreate($request->color);
            $legacyColorId = $color->id;
            $colorIds[] = $color->id;
        }

        $designId = $this->designInput($request->input('design_id'), (int) $request->product_id, 'design_id');

        $variation = new Variation();

        $variation->product_id = $request->product_id;
        $variation->color_id = $legacyColorId; // Keep for backward compatibility
        $variation->material_id = $materialId;
        $variation->design_id = $designId;
        $variation->size = $size;
        $variation->stock = $request->stock;
        $variation->weight = $request->weight;
        $variation->price = $request->price;
        if ($request->user()?->can('prices.wholesale')) {
            $variation->wholesale_price = $this->wholesaleInput($request->input('wholesale_price'));
        }
        $variation->active = $request->active;
        $variation->save();

        if (!empty($colorIds)) {
            $variation->colors()->sync($colorIds);
        }

        // TODO: be more clever!
        // update product average price
        Product::find($request->product_id)->updateAveragePrice();

        return $this->hideWholesale($request, $variation);
    }


    public function update(Request $request, Variation $variation)
    {
        // Normalize material_id and size - convert empty strings to null
        $updateData = $request->except(['colors', 'color', 'wholesale_price']);
        if ($request->user()?->can('prices.wholesale') && $request->exists('wholesale_price')) {
            $updateData['wholesale_price'] = $this->wholesaleInput($request->input('wholesale_price'));
        }

        if (isset($updateData['material_id']) && empty($updateData['material_id'])) {
            $updateData['material_id'] = null;
        }

        if (isset($updateData['size']) && (!empty($updateData['size']) && trim($updateData['size']) !== '')) {
            $updateData['size'] = trim($updateData['size']);
        } elseif (isset($updateData['size'])) {
            $updateData['size'] = null;
        }

        $productId = (int) ($updateData['product_id'] ?? $variation->product_id);
        if ($request->exists('design_id')) {
            $updateData['design_id'] = $this->designInput($request->input('design_id'), $productId, 'design_id');
        } elseif ($productId !== (int) $variation->product_id) {
            // A design belongs to one product.
            $updateData['design_id'] = null;
        }

        // Update variation fields
        $variation->update($updateData);

        // Handle multiple colors
        $colorIds = [];
        if ($request->has('colors') && is_array($request->colors) && count($request->colors) > 0) {
            foreach ($request->colors as $colorName) {
                $color = Color::findOrCreate($colorName);
                $colorIds[] = $color->id;
            }
        } elseif ($request->has('color')) {
            // Backward compatibility: single color as string
            $color = Color::findOrCreate($request->color);
            $variation->color_id = $color->id; // Keep for backward compatibility
            $colorIds[] = $color->id;
        }

        if (!empty($colorIds)) {
            $variation->colors()->sync($colorIds);
        }

        return $this->hideWholesale($request, $variation);
    }

    public function destroy(Variation $variation)
    {
        \App\Support\DeleteGuard::variation($variation);
        $variation->delete();
        return response()->json(null, 204);
    }

    /** A design to set on a variation: blank clears it; otherwise an active design of the variation's product. */
    private function designInput($value, int $productId, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 1) {
            throw ValidationException::withMessages([$field => ['طرح ساخت انتخاب‌شده وجود ندارد.']]);
        }
        $design = ProductDesign::find((int) $value);
        if (!$design || !$design->active || $design->product_id !== $productId) {
            throw ValidationException::withMessages([$field => ['طرح ساخت باید یک طرح فعال از همین محصول باشد.']]);
        }

        return $design->id;
    }

    /** Digits normalised, blank/null clears. */
    private function wholesaleInput($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = preg_replace('/[\s,٬]+/u', '', strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));

        return ctype_digit($value) ? (int) $value : null;
    }

    private function variationKey($size, array $colorNames, $materialId): string
    {
        $names = array_map(fn ($n) => (string) (is_array($n) ? ($n['name'] ?? '') : $n), $colorNames);
        sort($names);

        return trim((string) $size) . '|' . implode(',', $names) . '|' . ($materialId ?: '');
    }

    private function hideWholesale(Request $request, Variation $variation): Variation
    {
        if (!$request->user()?->can('prices.wholesale')) {
            $variation->makeHidden('wholesale_price');
        }

        return $variation;
    }
}

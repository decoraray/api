<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use App\Models\Product;
use App\Models\Variation;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductShowResource;
use App\Http\Resources\Admin\VariationShowAdminResource;

class VariationController extends Controller
{
    public function index()
    {
        $variations = Variation::with(['colors', 'color', 'material', 'product'])
            ->orderBy('id', 'desc')
            ->paginate(20);

        return VariationShowAdminResource::collection($variations);
    }

    public function show(Variation $variation)
    {
        $variation->load(['colors', 'color', 'material', 'product']);

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
                'size' => $size,
                'stock' => $variationData['stock'] ?? 0,
                'weight' => $variationData['weight'] ?? null,
                'price' => $variationData['price'],
                'active' => $variationData['active'] ?? true,
            ]);

            $variation->colors()->sync($colorIds);
        }

        // Update product average price
        $product->updateAveragePrice();

        return new ProductShowResource($product);
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

        $variation = new Variation();

        $variation->product_id = $request->product_id;
        $variation->color_id = $legacyColorId; // Keep for backward compatibility
        $variation->material_id = $materialId;
        $variation->size = $size;
        $variation->stock = $request->stock;
        $variation->weight = $request->weight;
        $variation->price = $request->price;
        $variation->active = $request->active;
        $variation->save();

        if (!empty($colorIds)) {
            $variation->colors()->sync($colorIds);
        }

        // TODO: be more clever!
        // update product average price
        Product::find($request->product_id)->updateAveragePrice();

        return $variation;
    }


    public function update(Request $request, Variation $variation)
    {
        // Normalize material_id and size - convert empty strings to null
        $updateData = $request->except(['colors', 'color']);

        if (isset($updateData['material_id']) && empty($updateData['material_id'])) {
            $updateData['material_id'] = null;
        }

        if (isset($updateData['size']) && (!empty($updateData['size']) && trim($updateData['size']) !== '')) {
            $updateData['size'] = trim($updateData['size']);
        } elseif (isset($updateData['size'])) {
            $updateData['size'] = null;
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

        return $variation;
    }

    public function destroy(Variation $variation)
    {
        $variation->delete();
        return response()->json(null, 204);
    }
}

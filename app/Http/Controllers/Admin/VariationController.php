<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use App\Models\Product;
use App\Models\Variation;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductShowResource;

class VariationController extends Controller
{
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

            $variation = $product->variations()->create([
                'product_id' => $product->id,
                'color_id' => null, // Keep for backward compatibility, but use colors relationship
                'material_id' => $materialId,
                'size' => $size,
                'stock' => $variationData['stock'] ?? 0,
                'weight' => $variationData['weight'] ?? null,
                'price' => $variationData['price'],
                'active' => $variationData['active'] ?? true,
            ]);

            // Handle multiple colors
            $colorIds = [];
            foreach ($variationData['colors'] as $colorName) {
                $color = Color::findOrCreate($colorName);
                $colorIds[] = $color->id;
            }
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

        $variation = new Variation();

        $variation->product_id = $request->product_id;
        $variation->material_id = $materialId;
        $variation->size = $size;
        $variation->stock = $request->stock;
        $variation->weight = $request->weight;
        $variation->price = $request->price;
        $variation->active = $request->active;
        $variation->save();

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

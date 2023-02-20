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
        $product->variations()->delete();
        foreach ($variations as $variation) {
            $color = Color::findOrCreate($variation['color']);
            $variation['color_id'] = $color->id;
            $product->variations()->create($variation);
        }

        return new ProductShowResource($product);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $color = Color::findOrCreate($request->color);

        $variation = new Variation();

        $variation->product_id = $request->product_id;
        $variation->color_id = $color->id;
        $variation->stock = $request->stock;
        $variation->weight = $request->weight;
        $variation->price = $request->price;
        $variation->active = $request->active;
        $variation->size = $request->size;
        $variation->save();

        // TODO: be more clever!
        // update product average price
        Product::find($request->product_id)->updateAveragePrice();

        return $variation;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Variation  $variation
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Variation $variation)
    {
        $color = Color::findOrCreate($request->color);
        $request->merge(['color_id' => $color->id]);

        $variation->update($request->all());
        return $variation;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Variation  $variation
     * @return \Illuminate\Http\Response
     */
    public function destroy(Variation $variation)
    {
        $variation->delete();
        return response()->json(null, 204);
    }
}

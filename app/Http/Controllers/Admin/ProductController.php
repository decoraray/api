<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use App\Models\Product;
use App\Models\Variation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductShowResource;
use App\Http\Resources\ProductAdminListResource;
use App\Http\Requests\Admin\CreateProductRequest;
use App\Http\Requests\Admin\AddProductVariationRequest;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return response()->json(
            ProductAdminListResource::collection(
                Product::take(10)
                    ->get()
            )
        );
        // return  Product::all();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateProductRequest $request)
    {
        $product = new Product($request->all());
        $product->save();
        return $product;
    }

    // add new product variation
    public function addVariation(AddProductVariationRequest $request)
    {
        $product = Product::find($request->product_id);

        $color = Color::findOrCreate($request->color);

        $variation = new Variation();

        $variation->product_id = $product->id;
        $variation->color_id = $color->id;
        $variation->stock = $request->stock;
        $variation->weight = $request->weight;
        $variation->price = $request->price;
        $variation->active = $request->active;
        $variation->size = $request->size;
        $variation->save();

        return $variation;
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function show(Product $product)
    {
        return new ProductShowResource($product);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function edit(Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Product $product)
    {
        $product->update($request->all());
        return $product;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function destroy(Product $product)
    {
        $product->delete();
        return $product;
    }


    public function importProducts()
    {

        $oldProducts = DB::connection('mysql')->table('imported_products')->get();

        $oldProducts->each(function ($product) {

            if ($product->parent_code == null) {

                $newProduct = new Product();
                $newProduct->title = $product->title;
                $newProduct->classifier = $product->code;
                $newProduct->slug = $product->slug;
                $newProduct->description = $product->description;
                $newProduct->category_id = 1;

                if (str_contains($product->status, 'غیر فعال')) {
                    $newProduct->active = false;
                }
                $newProduct->save();
            } else {
                $parent = Product::where('classifier', $product->parent_code)->first();
                $color = Color::findOrCreate($product->color);

                $newProductVariation = new Variation();
                $newProductVariation->product_id = $parent->id;
                $newProductVariation->color_id = $color->id;
                $newProductVariation->stock = $product->stock;
                $newProductVariation->weight = $product->weight;
                $newProductVariation->price = $product->price;

                if (str_contains($product->status, 'غیر فعال')) {
                    $newProductVariation->active = false;
                }
                $newProductVariation->save();
            }
        });

        return 'done';
    }
}

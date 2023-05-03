<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use App\Models\Product;
use App\Models\Variation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateProductRequest;
use App\Http\Resources\Admin\ProductAdminListResource;
use App\Http\Resources\Admin\ProductShowAdminResource;
use App\Models\Category;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $products = Product::with('categories')
            ->byCategory($request->category)
            ->when(
                $request->title && $request->title != 'null',
                function ($query) use ($request) {
                    return $query->where('title', 'like', "%{$request->title}%");
                }
            )
            ->paginate(40);
        return ProductAdminListResource::collection($products);
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

        // add the product to the request categories

        $product->categories()->attach($request->categories);

        return new ProductShowAdminResource($product);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function show(Product $product)
    {
        return new ProductShowAdminResource($product);
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

        if ($request->categories) {
            // if categoryes are array get all , else plunk ids and get them
            $categories = is_array($request->categories) ? $request->categories : $request->categories->pluck('id');
            $product->categories()->sync($categories);
        }

        return new ProductShowAdminResource($product);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Product  $product
     * @return \Illuminate\Http\Response
     */
    public function destroy(Product $product)
    {
        // delete images from disk
        $product->images->each(function ($image) {
            $image->delete();
        });

        $product->delete();
        return response()->json(null, 204);
    }


    // TODO : one time use
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

                // TODO : be more clever!
                $parent->updateAveragePrice();


                $newProductVariation->save();
            }
        });

        return 'done';
    }

    // TODO : one time use
    public function updateAveragePrice()
    {

        // for all products: 
        foreach (Product::all() as $product) {
            $product->updateAveragePrice();
        }
        return 'done';
    }

    // TODO : one time use
    // find all products and assign them to the category they belong to
    public function updateCategories()
    {
        $products = Product::all();

        $categories = Category::all();

        $products->each(function ($product) use ($categories) {
            $category = $categories->first(function ($category) use ($product) {
                return str_contains($product->title, $category->name);
            });

            if ($category) {
                $product->categories()->attach($category->id);
                // if category has parent add it to the product
                if ($category->parent) {
                    $product->categories()->attach($category->parent->id);
                }
            }
        });
    }
}

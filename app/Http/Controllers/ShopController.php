<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Rating;
use App\Models\Product;
use App\Models\Category;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\CartShowResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductShowResource;

class ShopController extends Controller
{
    // get initials data for shop page
    public function initial()
    {
        return response()->json([
            'products' => ProductListResource::collection(Product::active()->hasActiveVariation()->get()),
            'cart' => new CartShowResource(Cart::getOrCreate()),
        ]);
    }

    // get product list
    public function list()
    {
        return response()->json(
            ProductListResource::collection(
                Product::active()
                    ->take(50)
                    ->get()
            )
        );
    }

    // get product list by category
    public function search(SearchRequest $request)
    {
        return response()->json(
            ProductListResource::collection(
                Product::active()
                    ->when($request->category, function ($query) use ($request) {
                        return $query->where('category_id', $request->category);
                    })
                    ->when($request->product, function ($query) use ($request) {
                        return $query->where('id', '<>', $request->product);
                    })
                    ->take($request->take ?? 50)
                    ->offset($request->offset ?? 0)
                    ->get()
            )
        );
    }

    // popular products
    public function popular()
    {
        return response()->json(ProductListResource::collection(Product::active()->popular()->get()));
    }

    // product detail
    public function show($slugOrId)
    {
        $product = Product::where('id', $slugOrId)->orWhere('slug', $slugOrId)->firstOrFail();

        return response()->json(new ProductShowResource($product));
    }

    // give rating to product
    public function rating(Product $product)
    {
        $rating = $product->rating ?? Rating::make(['rating' => 0, 'count' => 0]);
        $rating->rate(request('rating'));
        $product->rating()->save($rating);

        return response()->json([
            'message' => 'success'
        ]);
    }

    // get category list
    public function categories()
    {
        return response()->json(CategoryResource::collection(Category::parents()->get()));
    }
}

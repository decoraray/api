<?php

namespace App\Http\Controllers;

use App\Http\Resources\CartShowResource;
use App\Models\Cart;
use App\Models\Rating;
use App\Models\Product;
use App\Models\Category;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductShowResource;
use App\Http\Resources\CategoryListResource;

class ShopController extends Controller
{
    // get initials data for shop page
    public function initial()
    {
        return response()->json([
            'products' => ProductListResource::collection(Product::active()->get()),
            'categories' => CategoryListResource::collection(Category::all()),
            'cart' => new CartShowResource(Cart::getOrCreate()),
        ]);
    }

    // get product list
    public function list()
    {
        return response()->json(ProductListResource::collection(Product::active()->get()));
    }

    // popular products
    public function popular()
    {
        return response()->json(ProductListResource::collection(Product::active()->get()));
    }

    // product detail
    public function show($slug)
    {
        $product = Product::where('slug', $slug)->firstOrFail();
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
        return response()->json(CategoryListResource::collection(Category::all()));
    }
}

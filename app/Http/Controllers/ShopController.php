<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\Product;
use App\Models\Category;
use App\Http\Resources\RatingShowResource;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductShowResource;
use App\Http\Resources\CategoryListResource;

class ShopController extends Controller
{

    public function initial()
    {
        return response()->json([
            'message' => 'shop',
            'Products' => ProductListResource::collection(Product::active()->get()),
            'Categories' => CategoryListResource::collection(Category::all()),
        ]);
    }

    // product list
    public function list()
    {
        return response()->json([
            'message' => 'products',
            'products' => ProductListResource::collection(Product::active()->get()),
        ]);
    }

    // product detail
    public function show(Product $product)
    {
        return response()->json([
            'message' => 'product',
            'product' => new ProductShowResource($product),
        ]);
    }

    public function rating(Product $product)
    {
        $rating = $product->rating ?? Rating::make(['rating' => 0, 'count' => 0]);
        $rating->rate(request('rating'));
        $product->rating()->save($rating);

        return response()->json([
            'message' => 'rating',
            'rating' => new RatingShowResource($rating),
        ]);
    }

    // category list
    public function categories()
    {
        return response()->json([
            'message' => 'category',
            'Categories' => CategoryListResource::collection(Category::all())
        ]);
    }

}
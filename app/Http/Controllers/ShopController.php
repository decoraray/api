<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Rating;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
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

    // search product
    public function listByCategory(Request $request, $category, $product = null)
    {

        if ($product) {
            $product = Product::where('id', $product)->orWhere('slug', $product)->firstOrFail();
            return response()->json(new ProductShowResource($product));
        }
        return response()->json(
            ProductListResource::collection(
                Product::active()
                    ->hasActiveVariation()
                    ->byCategory($category)
                    ->paginate($request->per_page ?? 20)
            )
        );
    }

    // get product list by category
    public function search(SearchRequest $request)
    {
        return response()->json(
            ProductListResource::collection(
                Product::active()
                    ->hasActiveVariation()
                    ->byCategory($request->category)
                    ->byTag($request->tag)
                    ->Sort()
                    ->when($request->product, function ($query) use ($request) {
                        return $query->where('id', '<>', $request->product);
                    })
                    ->when(
                        $request->search && $request->search != 'null',
                        function ($query) use ($request) {
                            return $query->where('title', 'like', "%{$request->search}%");
                        }
                    )
                    ->paginate($request->per_page ?? 20)
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


    /**
     * {   product_id: '...',
     *     page_url: '...',
     *     price: '...',
     *     availability: '...',
     *     old_price: '...',}
     */
    public function torobApi()
    {
        $products = Product::paginate(100);

        $data = [];

        foreach ($products as $product) {
            $data[] = [
                'product_id' => $product->id,
                'page_url' => route('product', $product->slug),
                'price' => $product->price,
                'availability' => $product->availability,
                'old_price' => $product->price,
            ];
        }

        return response()->json($data);
    }

    //   allProducts slugs
    public function allProducts()
    {
        $products = Product::pluck('slug');

        return response()->json($products);
    }
}

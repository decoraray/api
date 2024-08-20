<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;



class ProductSearch
{

    // get product list by category - POST
    public static function search(Request $request)
    {
        $cacheKey = 'products_' . md5(serialize($request->query()));

        return cache()->remember($cacheKey, 60, function () use ($request) {
            return self::getProductList($request);
        });
    }

    // Shared logic to fetch products based on common query criteria
    private static function getProductList($request)
    {
        $cleanedKeyword = self::cleanKeyword($request->keyword);

        try {
            return Product::search($cleanedKeyword)->options([
                'query_by' => 'search_title'
            ])
                ->query(
                    fn(Builder $query) => $query->active()
                        ->hasActiveVariation()
                        ->byCategory($request->category)
                        ->byTag($request->tag)
                        ->when($request->product, function ($query) use ($request) {
                            return $query->where('id', '<>', $request->product);
                        })
                        ->Sort()
                )
                ->paginate($request->per_page ?? 20);
        } catch (\Exception $e) {
            // If the search fails, we will try to fetch the products using the regular query
            Log::alert("message: " . $e->getMessage());
            return Product::active()
                ->hasActiveVariation()
                ->byCategory($request->category)
                ->byTag($request->tag)
                ->when($request->product, function ($query) use ($request) {
                    return $query->where('id', '<>', $request->product);
                })
                ->when($cleanedKeyword, function ($query) use ($cleanedKeyword) {
                    return $query->where('search_title', 'like', "%$cleanedKeyword%");
                })
                ->Sort()
                ->paginate($request->per_page ?? 20);
        }
    }

    // Method to clean the keyword by removing spaces
    private static function cleanKeyword($keyword)
    {
        $spaceLikeChars = [' ', '‌']; // Normal space and ZWNJ (Zero Width Non-Joiner)
        return $keyword && $keyword != 'null'
            ? str_replace($spaceLikeChars, '', $keyword)
            : null;
    }
}

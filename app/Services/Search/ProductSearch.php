<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Http\Request;


class ProductSearch
{

    // get product list by category - POST
    public static function search(Request $request)
    {
        $cacheKey = 'products_' . md5(serialize($request->query()));

        return cache()->remember($cacheKey,  60 * 2, function () use ($request) {
            return self::getProductList($request);
        });
    }


    // Shared logic to fetch products based on common query criteria
    private static function getProductList($request)
    {
        $cleanedKeyword = self::cleanKeyword($request->keyword);

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

    // Method to clean the keyword by removing spaces
    private static function cleanKeyword($keyword)
    {
        $spaceLikeChars = [' ', '‌']; // Normal space and ZWNJ (Zero Width Non-Joiner)
        return $keyword && $keyword != 'null'
            ? str_replace($spaceLikeChars, '', $keyword)
            : null;
    }
}

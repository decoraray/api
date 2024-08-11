<?php

namespace App\Http\Controllers\Admin;

use App\Models\Deal;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DealController extends Controller
{
    public function index(Request $request)
    {
        return Deal::paginate($request->per_page ?? 40);
    }

    //store
    // request date :  2024-08-12
    public function store(Request $request)
    {
        // request contains deal id:
        $deal = new Deal();
        $deal->slug = $request->slug;
        $deal->description = $request->description;
        $deal->discount = $request->discount;
        $deal->start_date = date('Y-m-d', strtotime($request->start));
        $deal->end_date = date('Y-m-d', strtotime($request->end));
        $deal->save();

        // request contains products array:
        $this->updateProducts($request->products, $deal->id);
    }

    // update
    public function update(Request $request, $id)
    {
        $deal = Deal::find($id);
        $deal->slug = $request->slug;
        $deal->description = $request->description;
        $deal->discount = $request->discount;
        $deal->start_date = date('Y-m-d', strtotime($request->start));
        $deal->end_date = date('Y-m-d', strtotime($request->end));
        $deal->save();

        // request contains products array:
        $this->updateProducts($request->products, $id);
    }

    // delete
    public function destroy($id)
    {
        $deal = Deal::find($id);
        $deal->delete();
    }

    public function addDeal(Request $request, $id)
    {
        $this->updateProducts($request->products, $id);
    }

    private function updateProducts($products, $id)
    {
        if ($products == null) {
            return;
        }
        if (!is_array($products)) {
            $parts = explode(",", $products);
            $products = array_map('intval', $parts);
        }
        foreach ($products as $productId) {
            $product = Product::find($productId);
            if ($product == null) {
                continue;
            }
            $product->deal_id = $id;
            $product->save();
        }
    }
}

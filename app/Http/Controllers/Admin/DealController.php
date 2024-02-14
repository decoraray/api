<?php

namespace App\Http\Controllers\Admin;

use App\Models\Deal;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DealController extends Controller
{
    public function index()
    {
        return Deal::all();
    }

    //store 
    public function store(Request $request)
    {

        // request contains deal id:
        $deal = new Deal();
        $deal->slug = $request->slug;
        $deal->description = $request->description;
        $deal->discount = $request->discount;
        $deal->start_date = $request->start;
        $deal->end_date = $request->end;
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
        $deal->start_date = $request->start;
        $deal->end_date = $request->end;
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

    private function updateProducts($products, $id)
    {
        foreach ($products as $productId) {
            $product = Product::find($productId);
            $product->deal_id = $id;
            $product->save();
        }
    }
}

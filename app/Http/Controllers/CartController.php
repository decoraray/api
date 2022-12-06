<?php

namespace App\Http\Controllers;

use App\Http\Requests\CartRequest;
use App\Http\Requests\ClearCartRequest;
use App\Models\Cart;
use App\Models\Order;

class CartController extends Controller
{
    //add to cart
    function add(CartRequest $request) {

        if (auth()->check()) {
            $cart = Cart::firstOrCreate([
                'user_id' => auth()->id()
            ]);
        } else {
            $cart = Cart::firstOrCreate([
                'uuid' => $request->getUuid() ?? uniqid(),
            ]);
        }

        $attachedIds = $cart->productsIds();
        if (!in_array($request->getProduct(), $attachedIds)) {
            $cart->products()->attach($request->getProduct(), [
                'description' => $request->getDescription(),
            ]);
        }else{
            $cart->products()->updateExistingPivot($request->getProduct(), [
                'quantity' => $cart->products()->find($request->getProduct())->pivot->quantity + 1,
                'description' => $request->getDescription(),
            ]);
        }

        return response()->json([
            'message' => 'product added to cart',
            'cart' => $cart,
            'product' => $cart->products,
        ]);
    }

    function remove(CartRequest $request) {

        $cart = Cart::where('uuid', $request->uuid)->first();
        $attachedIds = $cart->productsIds();
        if (in_array($request->getProduct(), $attachedIds)) {
            if($cart->products()->find($request->getProduct())->pivot->quantity > 1) {
                $cart->products()->updateExistingPivot($request->getProduct(), [
                    'quantity' => $cart->products()->find($request->getProduct())->pivot->quantity - 1,
                ]);
            }else{
                $cart->products()->detach($request->getProduct());
            }
        }

        return response()->json([
            'message' => 'product removed from cart',
            'cart' => $cart,
            'product' => $cart->products,
        ]);
    }

    function delete(CartRequest $request) {
        $cart = Cart::where('uuid', $request->uuid)->first();
        $cart->products()->detach($request->getProduct());
        return response()->json([
            'message' => 'product deleted',
            'cart' => $cart,
            'product' => $cart->products,
        ]);
    }

    function deleteAll(ClearCartRequest $request) {
        $cart = Cart::where('uuid', $request->uuid)->first();
        $cart->products()->detach();
        return response()->json([
            'message' => 'cart deleted',
            'cart' => $cart,
            'product' => $cart->products,
        ]);
    }

    private function createOrder($request)
    {

        $order = auth()->user()->orders->create([
            'address' => $request->address,
            'phone' => $request->phone,
            'status' => 'pending',
            'total' => $this->cart->total(),
        ]);

        $order->products()->attach($this->cart->products);
        return $order;
    }

    function purchase(CartRequest $request) {

        $cart = auth()->user()->cart;

        $order = $this->createOrder($request);

        return response()->json([
            'message' => 'purchase done',
            'cart' => $cart,
            'product' => $cart->products,
        ]);
    }

}

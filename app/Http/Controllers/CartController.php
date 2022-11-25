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

    function purchase(CartRequest $request) {

        $user = auth()->user();
        $cart = Cart::find($user->cart->id);

        $order = Order::create([
            'user_id' => $user->id,
            'total' => $cart->totalPrice(),
        ]);

        return response()->json([
            'message' => 'purchase done',
            'cart' => $cart,
            'product' => $cart->products,
        ]);
    }

}

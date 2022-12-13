<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Item;
use App\Models\Order;
use App\Models\Address;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\ClearCartRequest;
use App\Http\Resources\CartShowResource;
use App\Http\Resources\OrderShowResource;
use App\Http\Requests\CartInteractionRequest;
use App\Http\Requests\PaymentRequest;

class CartController extends Controller
{
    function index(Request $request)
    {
        $cart = Cart::getOrCreate($request->uuid);

        return response()->json(new CartShowResource($cart));
    }

    function add(CartInteractionRequest $request)
    {

        $cart = Cart::getOrCreate($request->uuid);

        $attachedIds = $cart->productsIds();
        if (!in_array($request->getProduct(), $attachedIds)) {
            $cart->products()->attach($request->getProduct(), [
                'description' => $request->getDescription(),
            ]);
        } else {
            $cart->products()->updateExistingPivot($request->getProduct(), [
                'quantity' => $cart->products()->find($request->getProduct())->pivot->quantity + 1,
                'description' => $request->getDescription(),
            ]);
        }

        return response()->json([
            'message' => 'product added to cart',
            'cart_uuid' => $cart->uuid ?? null,
        ]);
    }

    function remove(CartInteractionRequest $request)
    {

        $cart = Cart::getOrCreate($request->uuid);

        $attachedIds = $cart->productsIds();
        if (in_array($request->getProduct(), $attachedIds)) {
            if ($cart->products()->find($request->getProduct())->pivot->quantity > 1) {
                $cart->products()->updateExistingPivot($request->getProduct(), [
                    'quantity' => $cart->products()->find($request->getProduct())->pivot->quantity - 1,
                ]);
            } else {
                $cart->products()->detach($request->getProduct());
            }
        }

        return response()->json([
            'message' => 'product removed from cart',
        ]);
    }

    function delete(CartInteractionRequest $request)
    {

        $cart = Cart::getOrCreate($request->uuid);

        $cart->products()->detach($request->getProduct());
        return response()->json([
            'message' => 'product deleted',
        ]);
    }

    function clear(ClearCartRequest $request)
    {

        $cart = Cart::getOrCreate($request->uuid);

        $cart->products()->detach();
        return response()->json([
            'message' => 'cart deleted',
        ]);
    }

    // TODO : this function is not working
    function discount(Request $request)
    {
        $cart = Cart::where('uuid', $request->uuid)->first();
        $cart->discount_id = $request->discount_id;
        $cart->save();
        return response()->json([
            'message' => 'discount added',
        ]);
    }
}

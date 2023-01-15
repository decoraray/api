<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use Illuminate\Http\Request;
use App\Http\Requests\ClearCartRequest;
use App\Http\Resources\CartShowResource;
use App\Http\Requests\CartInteractionRequest;

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
        $attachedIds = $cart->variationsIds();
        if (!in_array($request->getVariation(), $attachedIds)) {
            $cart->variations()->attach($request->getVariation(), [
                'description' => $request->getDescription(),
            ]);
        } else {
            $cart->variations()->updateExistingPivot($request->getVariation(), [
                'quantity' => $cart->variations()->find($request->getVariation())->pivot->quantity + 1,
                'description' => $request->getDescription(),
            ]);
        }

        return response()->json([
            'message' => 'product added to cart',
            'cart_uuid' => $cart->uuid ?? null,
            'cart' => new CartShowResource($cart),
        ]);
    }

    function remove(CartInteractionRequest $request)
    {

        $cart = Cart::getOrCreate($request->uuid);

        $attachedIds = $cart->variationsIds();
        if (in_array($request->getVariation(), $attachedIds)) {
            if ($cart->variations()->find($request->getVariation())->pivot->quantity > 1) {
                $cart->variations()->updateExistingPivot($request->getVariation(), [
                    'quantity' => $cart->variations()->find($request->getVariation())->pivot->quantity - 1,
                ]);
            } else {
                $cart->variations()->detach($request->getVariation());
            }
        }

        return response()->json([
            'message' => 'product removed from cart',
            'cart' => new CartShowResource($cart),
        ]);
    }

    function delete(CartInteractionRequest $request)
    {

        $cart = Cart::getOrCreate($request->uuid);

        $cart->variations()->detach($request->getVariation());
        return response()->json([
            'message' => 'product deleted',
            'cart' => new CartShowResource($cart),
        ]);
    }

    function clear(ClearCartRequest $request)
    {

        $cart = Cart::getOrCreate($request->uuid);

        $cart->variations()->detach();
        return response()->json([
            'message' => 'cart deleted',
            'cart' => new CartShowResource($cart),

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
            'cart' => new CartShowResource($cart),
        ]);
    }
}

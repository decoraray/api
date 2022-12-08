<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Item;
use App\Models\Order;
use App\Models\Address;
use Illuminate\Http\Request;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\ClearCartRequest;
use App\Http\Resources\CartShowResource;
use App\Http\Resources\OrderShowResource;
use App\Http\Requests\CartInteractionRequest;

class CartController extends Controller
{
    function index(Request $request) {

        if (auth()->check()) {
            $cart = Cart::firstOrCreate([
                'user_id' => auth()->id()
            ]);
        } else {
            $cart = Cart::firstOrCreate([
                'uuid' => $request->uuid ?? uniqid(),
            ]);
        }

        return response()->json(new CartShowResource($cart));
    }
    //add to cart
    function add(CartInteractionRequest $request) {

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
        ]);
    }

    function remove(CartInteractionRequest $request) {

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
        ]);
    }

    function delete(CartInteractionRequest $request) {
        $cart = Cart::where('uuid', $request->uuid)->first();
        $cart->products()->detach($request->getProduct());
        return response()->json([
            'message' => 'product deleted',
        ]);
    }

    function deleteAll(ClearCartRequest $request) {
        $cart = Cart::where('uuid', $request->uuid)->first();
        $cart->products()->detach();
        return response()->json([
            'message' => 'cart deleted',
        ]);
    }

    // TODO : this function is not working
    function discount(Request $request) {
        $cart = Cart::where('uuid', $request->uuid)->first();
        $cart->discount_id = $request->discount_id;
        $cart->save();
        return response()->json([
            'message' => 'discount added',
        ]);
    }

    function purchase(PurchaseRequest $request) {

        $cart = Cart::firstOrCreate([
            'user_id' => auth()->id()
        ]);

        if ($cart->isEmpty) {
            return response()->json([
                'message' => 'cart is empty',
            ], 400);
        }

        $order = $this->createOrder($request->address_id);

        $cart->products()->detach();
        
        return response()->json(new OrderShowResource($order), 201);
    }

    private function createOrder($address_id) {
        
        $user = auth()->user();

        $address = Address::where('uuid', $address_id)->first();

        $cart = $user->cart;

        $order = Order::create([
            'user_id' => $user->id,
            'uuid' => uniqid(),
            'address' => $address->address,
            'city' => $address->city,
            'county' => $address->county,
            'postal_code' => $address->postal_code,
            'phone' => $address->phone,
            'status' => 'pending',
            'total' => $cart->total,
            'discount_amount' => $cart->discountAmount,
            'discount_id' => $cart->discount_id,
            'address_id' => $address->id,
        ]);

        for ($i = 0; $i < $cart->products->count(); $i++) {
            Item::create([
                'title' => $cart->products[$i]->title,
                'order_id' => $order->id,
                'product_id' => $cart->products[$i]->id,
                'quantity' => $cart->products[$i]->pivot->quantity,
                'price' => $cart->products[$i]->price,
            ]);
        }
        
        return $order;
    }

}

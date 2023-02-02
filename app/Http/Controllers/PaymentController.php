<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Item;
use App\Models\Order;
use App\Models\Address;
use App\Models\Payment;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\CheckoutRequest;
use App\Services\Payment\PaymentService;
use App\Http\Resources\OrderShowResource;
use App\Support\Payment\Gateways\SamanDriver;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {
        //
    }

    function checkout(CheckoutRequest $request)
    {

        $cart = Cart::getOrCreate(); // TODO : get cart by uuid 

        if ($cart->isEmpty) {
            return response()->json([
                'message' => 'cart is empty',
            ], 400);
        }

        $order = $this->createOrder($request->address_id, $request->payment_method);

        // $cart->products()->detach();

        return response()->json(new OrderShowResource($order), 201);
    }


    private function gatewayFactory($gateway)
    {
        $g = [
            'saman' => SamanDriver::class,
            'pasargad' => Pasargad::class,
            'mellat'  => Mellat::class,
            'melli'  => Melli::class,
            'sepah'  => Sepah::class,
        ][$gateway];
        return resolve($g);
    }

    public function pay(PaymentRequest $request)
    {
        $user = auth()->user();

        $order = Order::where('uuid', $request->order_id)->first();

        if (empty($order) || $order->status == 'paid') {
            return response()->json([
                'message' => 'order already paid or does not exist',
            ], 400);
        }

        $payment = Payment::create([
            'uuid' => uniqid(),
            'amount' => round($order->final_price),
            'description' => 'payment for order ' . $order->uuid,
            'user_phone' => $user->phone,
            'method' => $request->method ?? 'card',
            'user_id' => $user->id,
            'order_id' => $order->id,
            'status' => 'pending',
        ]);

        // selection of gateway & payment
        $this->gatewayFactory($request->gateway)->Pay($payment);

        if ($payment->status == 'success') {
            $order->status = 'paid';
            $order->save();
        } else {
            return response()->json([
                'message' => 'payment failed',
            ], 400);
        }

        return response()->json([
            'message' => 'payment successful',
        ], 201);
    }

    private function createOrder($addressId, $paymentMethod)
    {

        $user = auth()->user();

        $address = Address::where('uuid', $addressId)->first();

        $cart = $user->cart;

        $order = Order::create([
            'user_id' => $user->id,
            'uuid' => uniqid(),
            'address' => $address->address,
            'city' => $address->city,
            'county' => $address->county,
            'postal_code' => $address->postal_code,
            'phone' => $address->phone,
            'payment_method' => $paymentMethod,
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

    public function orders()
    {
        $user = auth()->user();
        $orders = $user->orders;
        return response()->json(OrderShowResource::collection($orders));
    }

    public function payValidate(PaymentRequest $request)
    {
        return response()->json([
            'message' => 'validation successful',
        ], 201);
    }

    public function verify($uuid)
    {

        $this->paymentService->verify($uuid);

        if (empty($order)) {
            return response()->json([
                'message' => 'order does not exist',
            ], 400);
        }
        return response()->json(new OrderShowResource($order));
    }
}

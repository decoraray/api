<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\City;
use App\Models\Item;
use App\Models\Order;
use App\Models\Address;
use App\Models\Payment;
use App\Models\Shipping;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use App\Http\Requests\CheckoutRequest;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Redirect;
use App\Http\Resources\OrderShowResource;

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

        // $order = $this->createOrder($request);
        $order = Order::createFromRequest($request);

        $paymentMethod = $request->payment_method;
        if ($paymentMethod == 'cash') {
            $order->update([
                'status' => OrderStatus::PENDING,
            ]);

            $cart->clear();

            return response()->json(
                ['url' => ''],
                201
            );
        }

        $payment = $this->createPayment($request, $order);

        return response()->json(
            ['url' => $this->paymentService->pay($payment, $request->gateway)],
            201
        );
    }

    private function createOrder($request): Order
    {

        $addressId = $request->address_id;
        // $shipping_id = $request->shipping_id;
        $paymentMethod = $request->payment_method;

        $user = auth()->user();

        $address = Address::where('uuid', $addressId)->first();

        $cart = $user->cart;

        // $shipping = Shipping::find($shipping_id);
        $shipping = City::where('id', $address->city_id)->first()->shipping;

        // create order
        $order = Order::create([
            'user_id' => $user->id,
            'uuid' => uniqid(),
            'address' => $address->address,
            'city' => $address->city,
            'county' => $address->county,
            'postal_code' => $address->postal_code,
            'phone' => $address->phone,
            'status' => OrderStatus::PENDING,
            'total' => $cart->total + $shipping->price,
            'payment_method' => $paymentMethod,
            'discount_amount' => $cart->discountAmount,
            'discount_id' => $cart->discount_id,
            'address_id' => $address->id,
            'shipping_id' => $shipping->id,
        ]);

        // add items to order
        for ($i = 0; $i < $cart->variations->count(); $i++) {
            Item::create([
                'title' => $cart->variations[$i]->product->title,
                'slug' => $cart->variations[$i]->product->slug,
                'order_id' => $order->id,
                'product_id' => $cart->variations[$i]->product->id,
                'variation_id' => $cart->variations[$i]->id,
                'quantity' => $cart->variations[$i]->pivot->quantity,
                'price' => $cart->variations[$i]->calculatePrice(),
            ]);
        }

        // calculate payable amount



        $cart->clear();

        return $order;
    }

    private function createPayment(Request $request, Order $order): Payment
    {
        $user = auth()->user();
        return Payment::create([
            'uuid' => uniqid(),
            'amount' => $order->getPayableAmount(),
            'description' => 'payment for order ' . $order->uuid,
            'user_phone' => $user->phone,
            'method' => $request->payment_method,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'gateway' => $request->gateway,
            'status' => 'pending',
        ]);
    }

    // show orders for user 
    public function orders()
    {
        $user = auth()->user();
        $orders = $user->orders;
        return response()->json(OrderShowResource::collection($orders));
    }

    public function verify(Request $request)
    {
        $payment = Payment::where('uuid', $request->clientrefid)->first();

        if (empty($payment)) {
            return response()->json([
                'message' => 'payment not found',
            ], 400);
        }

        $payment->update([
            'card_number' => $request->cardnumber,
            'card_pan' => $request->cardhashpan,
            'ref_id' =>  $request->refid,
        ]);

        $payment = $this->paymentService->verify($payment, $request->gateway);

        if ($payment->status == 'paid') {
            $payment->order->update([
                'status' => OrderStatus::PAID,
            ]);
            $payment->user->cart->clear();
        } else {
            return Redirect::to(config('app.front_url') . '/payments/error/');
        }

        return Redirect::to(config('app.front_url') . '/payments/success/');
    }
}

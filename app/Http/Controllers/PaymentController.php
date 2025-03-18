<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use App\Http\Requests\CheckoutRequest;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Redirect;
use App\Http\Resources\OrderShowResource;
use Illuminate\Support\Facades\Log;

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
        Log::info('Payment verification request received', $request->all());

        $data = json_decode($request->data, true); // Decode JSON string into an array
        $payment = Payment::where('uuid', $data['clientRefId'])->first();

        if (empty($payment)) {
            return response()->json([
                'message' => 'payment not found',
            ], 400);
        }

        $payment->update([
            'card_number' => $data['cardNumber'],
            'card_pan' => $data['cardHashPan'],
            'ref_id' => $data['paymentRefId'],
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

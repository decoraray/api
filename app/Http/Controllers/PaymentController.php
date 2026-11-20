<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Services\Orders\OrderNotifier;
use App\Models\Order;
use App\Models\Payment;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use App\Http\Requests\CheckoutRequest;
use App\Services\Payment\PaymentGatewayException;
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
            app(\App\Services\Leads\LeadService::class)->orderPaid($order->fresh('user'), null, 'order_placed');
            return response()->json(
                ['url' => ''],
                201
            );
        }
        $payment = $this->createPayment($request, $order);

        try {
            $url = $this->paymentService->pay($payment, $request->gateway);
        } catch (PaymentGatewayException $e) {
            report($e); // full gateway response goes to the log and Sentry

            return response()->json([
                'message' => 'اتصال به درگاه پرداخت ممکن نشد. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
                'order' => $order->uuid,
            ], 502);
        }

        return response()->json(['url' => $url], 201);
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

        $data = null;

        // Case 1: Correct format - "data" is a stringified JSON
        if ($request->has('data')) {
            $raw = $request->input('data');
            $data = is_array($raw) ? $raw : json_decode($raw, true);
        }

        // Case 2: Incorrect format - first key is a JSON string
        if ($data === null) {
            $keys = array_keys($request->all());
            if (!empty($keys)) {
                $raw = $keys[0];
                $data = json_decode($raw, true);
            }
        }

        // Optional: try fixing known malformed JSON (e.g. ":_" instead of ":")
        if (!is_array($data) && isset($raw) && is_string($raw)) {
            $sanitized = str_replace(':_', ':', $raw);
            if ($sanitized !== $raw) {
                $data = json_decode($sanitized, true);
            }
        }

        if (!is_array($data)) {
            Log::error('Failed to decode payment data', ['raw_data' => $raw ?? null]);
            return response()->json(['message' => 'invalid payment data'], 400);
        }

        $payment = Payment::where('uuid', $data['clientRefId'])->first();

        if (empty($payment)) {
            return response()->json([
                'message' => 'payment not found',
            ], 400);
        }

        try {
            $payment->update([
                'card_number' => $data['cardNumber'] ?? null,
                'card_pan' => $data['cardHashPan'] ?? null,
                'payment_code' => $data['paymentCode'] ?? null,
                'ref_id' => $data['paymentRefId'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update payment', ['error' => $e->getMessage()]);
            $payment->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            return Redirect::to(config('app.front_url') . '/payments/error/');
        }

        $payment = $this->paymentService->verify($payment, $request->gateway);

        if ($payment->status == 'paid') {

            $this->startProduction($payment->order);
            $payment->user->cart?->clear();
            app(OrderNotifier::class)->notify($payment->order, 'paid');
            if ($payment->order->payments()->where('status', 'paid')->count() === 1) {
                app(\App\Services\Leads\LeadService::class)->orderPaid($payment->order->fresh('user'), $payment, 'payment');
            }
        } else {
            return Redirect::to(config('app.front_url') . '/payments/error/');
        }

        return Redirect::to(config('app.front_url') . '/payments/success/');
    }

    /**
     * A paid storefront order goes straight to production: confirmed, with its work orders.
     * An order staff already moved on (e.g. cancelled) is left alone.
     */
    private function startProduction(Order $order): void
    {
        $current = $order->status instanceof OrderStatus ? $order->status : OrderStatus::tryFrom((string) $order->status);
        if (!in_array($current, [OrderStatus::PENDING, OrderStatus::PAID], true)) {
            return;
        }

        $order->update(['status' => OrderStatus::CONFIRMED]);

        // The payment is already verified; a production hiccup must not show the customer an error.
        try {
            app(\App\Services\Production\WorkOrderService::class)->createForOrder($order);
        } catch (\Throwable $e) {
            Log::error('Failed to create work orders after payment', ['order' => $order->uuid, 'error' => $e->getMessage()]);
            report($e);
        }
    }
}

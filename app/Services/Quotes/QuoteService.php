<?php

namespace App\Services\Quotes;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethods;
use App\Models\Item;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    /**
     * Record a payment on a quote. The first payment creates the production order.
     */
    public function recordPayment(Quote $quote, int $amount, string $method, string $paidAt, ?string $reference, ?User $staff): Quote
    {
        return DB::transaction(function () use ($quote, $amount, $method, $paidAt, $reference, $staff) {
            $quote = Quote::whereKey($quote->getKey())->lockForUpdate()->firstOrFail();
            $quote->load('lines');

            if ($quote->status === 'cancelled') {
                throw ValidationException::withMessages(['quote' => ['این پیش‌فاکتور لغو شده است.']]);
            }

            $totals = QuoteTotals::for($quote);
            if ($amount > $totals['remaining']) {
                throw ValidationException::withMessages(['amount' => ['مبلغ پرداخت بیشتر از مانده پیش‌فاکتور است.']]);
            }

            $methodEnum = PaymentMethods::fromName($method);
            $paidAtDate = \Illuminate\Support\Carbon::parse($paidAt);

            $order = $quote->order_id ? Order::find($quote->order_id) : null;
            if (!$order) {
                $order = $this->createOrder($quote, $totals, $methodEnum);
                $quote->order_id = $order->id;
            }

            $payment = new Payment([
                'uuid' => (string) Str::uuid(),
                'user_phone' => $quote->phone,
                'amount' => $amount,
                'status' => 'paid',
                'method' => $methodEnum->value,
                'gateway' => null,
                'ref_id' => $reference,
                'user_id' => $quote->user_id,
                'order_id' => $order->id,
            ]);
            $payment->created_at = $paidAtDate;
            $payment->updated_at = $paidAtDate;
            $payment->save();

            $quote->status = 'paid';
            $quote->save();

            return $quote->fresh(['lines', 'order', 'createdBy']);
        });
    }

    private function createOrder(Quote $quote, array $totals, PaymentMethods $method): Order
    {
        $order = Order::create([
            'uuid' => uniqid(),
            'user_id' => $quote->user_id,
            'address' => (string) $quote->address,
            'city' => '',
            'county' => '',
            'postal_code' => '',
            'phone' => $quote->phone,
            'status' => OrderStatus::CONFIRMED->value,
            'payment_method' => $method->name,
            'total' => $totals['lines'] + $totals['shipping'],
            'discount_amount' => $totals['discount'],
            'source' => 'staff',
            'quote_id' => $quote->id,
            'description' => null,
        ]);

        foreach ($quote->lines as $line) {
            $slug = 'custom';
            if ($line->product_id) {
                $slug = \App\Models\Product::find($line->product_id)?->slug ?? 'custom';
            }
            Item::create([
                'title' => $line->description,
                'slug' => $slug,
                'order_id' => $order->id,
                'product_id' => $line->product_id,
                'variation_id' => $line->variation_id,
                'quantity' => $line->quantity,
                'price' => $line->unit_price,
                'discount' => 0,
                'description' => $line->description,
                'dimensions' => $line->dimensions,
                'color' => $line->color,
            ]);
        }

        return $order;
    }
}

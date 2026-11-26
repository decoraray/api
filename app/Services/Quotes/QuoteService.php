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
    public function recordPayment(Quote $quote, int $amount, string $method, string $paidAt, ?string $reference, ?User $staff, array $receiptIds = []): Quote
    {
        $createdOrder = null;
        $recorded = null;
        $receipts = app(\App\Services\Payment\PaymentReceiptRules::class);
        $receipts->assertAllowed(PaymentMethods::fromName($method), $receiptIds);

        $result = DB::transaction(function () use ($quote, $amount, $method, $paidAt, $reference, $staff, $receiptIds, $receipts, &$createdOrder, &$recorded) {
            $quote = Quote::whereKey($quote->getKey())->lockForUpdate()->firstOrFail();
            $quote->load('lines');

            if ($quote->status === 'cancelled') {
                throw ValidationException::withMessages(['quote' => ['این پیش‌فاکتور لغو شده است.']]);
            }

            $totals = QuoteTotals::for($quote);
            if ($amount > $totals['remaining']) {
                throw ValidationException::withMessages(['amount' => ['مبلغ پرداخت بیشتر از مانده پیش‌فاکتور است.']]);
            }

            if ($quote->order_id) {
                $current = Order::whereKey($quote->order_id)->value('status');
                if (in_array($current, [OrderStatus::CANCELED->value, OrderStatus::RETURNED->value], true)) {
                    throw ValidationException::withMessages(['quote' => ['سفارش این پیش‌فاکتور لغو یا مرجوع شده است و پرداخت جدید ثبت نمی‌شود.']]);
                }
            }

            $methodEnum = PaymentMethods::fromName($method);
            $paidAtDate = \Illuminate\Support\Carbon::parse($paidAt);

            $order = $quote->order_id ? Order::find($quote->order_id) : null;
            if (!$order) {
                $order = $this->createOrder($quote, $totals, $methodEnum);
                $quote->order_id = $order->id;
                $createdOrder = $order;
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
            $receipts->attach($payment, $receiptIds, $staff);
            $recorded = $payment;

            $quote->status = $amount >= $totals['remaining'] ? 'paid' : 'deposit_paid';
            $quote->save();

            return $quote->fresh(['lines', 'order', 'createdBy']);
        });

        if ($recorded && $result->lead_id) {
            app(\App\Services\Leads\LeadService::class)->quotePaid($result, $recorded, (bool) $createdOrder, $staff);
        } elseif ($recorded && $createdOrder) {
            // A quote typed without a lead still closes the customer's open lead, found by phone.
            app(\App\Services\Leads\LeadService::class)->orderPaid($createdOrder->fresh(), $recorded, 'payment', $staff);
        }

        if ($createdOrder) {
            app(\App\Services\Orders\OrderNotifier::class)->notify($createdOrder, 'confirmed');
        }

        return $result;
    }

    /**
     * Set a quote's paid status from the payments that still count (not voided): paid when they
     * cover the payable total, deposit_paid when they cover part of it, sent when none remain.
     * Draft and cancelled quotes are left alone.
     */
    public function syncPaymentStatus(Quote $quote): Quote
    {
        if (in_array($quote->status, ['draft', 'cancelled'], true)) {
            return $quote;
        }

        $totals = QuoteTotals::for($quote->loadMissing('lines'));
        $status = match (true) {
            $totals['paid'] <= 0 => 'sent',
            $totals['paid'] >= $totals['payable'] => 'paid',
            default => 'deposit_paid',
        };

        if ($quote->status !== $status) {
            $quote->status = $status;
            $quote->save();
        }

        return $quote;
    }

    private function createOrder(Quote $quote, array $totals, PaymentMethods $method): Order
    {
        $lead = $quote->lead_id ? \App\Models\Lead::find($quote->lead_id) : null;

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
            'channel' => $lead?->source,
            'tracked_link_id' => $lead?->tracked_link_id,
            'attribution' => $lead?->attribution,
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
                'color_id' => $line->color_id,
                'design_id' => $line->design_id,
            ]);
        }

        // The order is born confirmed, so it goes to the workshop at once.
        app(\App\Services\Production\WorkOrderService::class)->createForOrder($order);

        return $order;
    }
}

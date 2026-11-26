<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethods;
use App\Models\Item;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipping;
use App\Models\User;
use App\Models\Variation;
use App\Support\Channel;
use App\Services\Production\DesignResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates an order on behalf of a customer (phone, Instagram, in person). Totals are computed here, never trusted from the client. */
class OrderCreationService
{
    /** @return array{lines:int, shipping:int, discount:int, total:int} */
    public function totals(array $lines, int $shipping, int $discount): array
    {
        $sum = 0;
        foreach ($lines as $line) {
            $sum += (int) $line['unit_price'] * (int) $line['quantity'];
        }

        return [
            'lines' => $sum,
            'shipping' => $shipping,
            'discount' => $discount,
            'total' => max(0, $sum + $shipping - $discount),
        ];
    }

    public function create(array $data, ?User $staff): Order
    {
        $receipts = app(\App\Services\Payment\PaymentReceiptRules::class);
        if (!empty($data['payment'])) {
            $receipts->assertAllowed(PaymentMethods::fromName($data['payment']['method']), $data['payment']['receipt_ids'] ?? [], 'payment.receipt_ids');
        }

        $order = DB::transaction(function () use ($data, $staff, $receipts) {
            $user = $this->customer($data['phone'], $data['customer_name'] ?? null);
            $shipping = Shipping::findOrFail($data['shipping_id']);
            $lines = $this->resolveLines($data['lines']);

            $shippingPrice = array_key_exists('shipping_price', $data) && $data['shipping_price'] !== null
                ? (int) $data['shipping_price']
                : $this->defaultShipping($shipping, $lines);
            $discount = (int) ($data['discount_amount'] ?? 0);
            $totals = $this->totals($lines, $shippingPrice, $discount);

            $payment = $data['payment'] ?? null;
            // Staff orders go to production when saved; waiting states are not offered (an old form may still send one).
            $status = isset($data['status']) ? OrderStatus::fromName($data['status']) : OrderStatus::CONFIRMED;
            if (in_array($status, [OrderStatus::PENDING, OrderStatus::PAID], true)) {
                $status = OrderStatus::CONFIRMED;
            }

            $channel = $data['channel'] ?? Channel::DIRECT;
            if (!array_key_exists($channel, Channel::labels())) {
                throw ValidationException::withMessages(['channel' => ['کانال فروش نامعتبر است.']]);
            }

            $order = Order::create([
                'uuid' => uniqid(),
                'user_id' => $user->id,
                'address' => $data['address'],
                'city' => $data['city'] ?? '',
                'county' => $data['county'] ?? '',
                'postal_code' => $data['postal_code'] ?? '',
                'phone' => $data['phone'],
                'status' => $status->value,
                'payment_method' => $data['payment_method'],
                'total' => $totals['lines'] + $totals['shipping'],
                'discount_amount' => $totals['discount'],
                'shipping_id' => $shipping->id,
                'description' => $data['description'] ?? null,
                'source' => 'staff',
                'channel' => $channel,
            ]);

            foreach ($lines as $line) {
                Item::create([
                    'title' => $line['title'],
                    'slug' => $line['slug'],
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'variation_id' => $line['variation_id'],
                    'quantity' => $line['quantity'],
                    'price' => $line['unit_price'],
                    'discount' => 0,
                    'description' => $line['description'],
                    'dimensions' => $line['dimensions'],
                    'color' => $line['color'],
                    'color_id' => $line['color_id'],
                    'design_id' => $line['design_id'],
                ]);
            }

            if ($payment) {
                $method = PaymentMethods::fromName($payment['method']);
                $recorded = Payment::create([
                    'uuid' => (string) Str::uuid(),
                    'user_phone' => $data['phone'],
                    'amount' => (int) $payment['amount'],
                    'status' => 'paid',
                    'method' => $method->value,
                    'gateway' => null,
                    'ref_id' => $payment['reference'] ?? null,
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                ]);
                $receipts->attach($recorded, $payment['receipt_ids'] ?? [], $staff, 'payment.receipt_ids');
            }

            activity()
                ->performedOn($order)
                ->causedBy($staff)
                ->withProperties(['source' => 'staff', 'total' => $totals['total'], 'status' => $status->value])
                ->log('manual-create');

            if (\App\Enums\ProductionStage::orderIsProducible($status)) {
                app(\App\Services\Production\WorkOrderService::class)->createForOrder($order);
            }

            return $order;
        });

        if (!empty($data['notify'])) {
            $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::from($order->status);
            $event = $status === OrderStatus::PAID ? 'paid' : OrderNotifier::eventForStatus($status);
            if ($event) {
                app(OrderNotifier::class)->notify($order, $event);
            }
        }

        return $order;
    }

    private function customer(string $phone, ?string $name): User
    {
        $user = User::whereIn('phone', \App\Support\Phone::variants($phone))->first();
        if ($user) {
            return $user;
        }
        if (!$name) {
            throw ValidationException::withMessages(['customer_name' => ['برای مشتری جدید نام لازم است.']]);
        }

        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'phone' => $phone,
            'password' => Hash::make(Str::random(32)),
        ]);
    }

    /** Fills title, slug, product and price from the catalog unless the staff overrode them. */
    private function resolveLines(array $input): array
    {
        $lines = [];
        foreach (array_values($input) as $index => $row) {
            $variation = !empty($row['variation_id']) ? Variation::with('product.deal')->findOrFail($row['variation_id']) : null;
            $designId = !empty($row['design_id']) ? (int) $row['design_id'] : null;
            app(DesignResolver::class)->assertAllowed($designId, $variation?->product_id, "lines.$index.design_id");
            $lines[] = [
                'title' => $row['title'] ?? $variation?->product->title,
                'slug' => $variation?->product->slug ?? 'custom',
                'product_id' => $variation?->product->id,
                'variation_id' => $variation?->id,
                'quantity' => (int) $row['quantity'],
                'unit_price' => (int) ($row['unit_price'] ?? $variation->calculatedPrice),
                'description' => $row['description'] ?? null,
                'dimensions' => $row['dimensions'] ?? null,
                'color' => $row['color'] ?? null,
                'color_id' => \App\Support\Orders\LineColour::resolve($row['color_id'] ?? null, $variation?->id),
                'design_id' => $designId,
            ];
        }

        return $lines;
    }

    /** Checkout's formula: base price plus additional_percent of it for each unit beyond the first. */
    private function defaultShipping(Shipping $shipping, array $lines): int
    {
        $units = max(1, array_sum(array_column($lines, 'quantity')));

        return (int) round($shipping->price + ($shipping->price * ($units - 1) * $shipping->additional_percent / 100));
    }
}

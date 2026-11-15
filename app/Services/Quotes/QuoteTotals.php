<?php

namespace App\Services\Quotes;

use App\Models\Quote;

class QuoteTotals
{
    /**
     * @return array{lines:int,shipping:int,discount:int,payable:int,deposit:int,paid:int,remaining:int}
     */
    public static function for(Quote $quote): array
    {
        $lines = 0;
        foreach ($quote->lines as $line) {
            $lines += (int) $line->quantity * (int) $line->unit_price;
        }

        $paid = 0;
        if ($quote->order_id) {
            $paid = (int) \App\Models\Payment::where('order_id', $quote->order_id)
                ->where('status', 'paid')
                ->sum('amount');
        }

        return self::compute(
            $lines,
            $quote->shipping_mode,
            (int) $quote->shipping_amount,
            (int) $quote->discount,
            (int) $quote->deposit_percent,
            $paid
        );
    }

    public static function compute(int $lines, string $shippingMode, int $shippingAmount, int $discount, int $depositPercent, int $paid = 0): array
    {
        $shipping = $shippingMode === 'on_delivery' ? 0 : $shippingAmount;
        $payable = $lines + $shipping - $discount;
        $deposit = (int) (round($payable * $depositPercent / 100 / 1000) * 1000);

        return [
            'lines' => $lines,
            'shipping' => $shipping,
            'discount' => $discount,
            'payable' => $payable,
            'deposit' => $deposit,
            'paid' => $paid,
            'remaining' => $payable - $paid,
        ];
    }
}

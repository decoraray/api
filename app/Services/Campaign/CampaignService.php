<?php

namespace App\Services\Campaign;

use App\Models\Order;

class CampaignService
{

    public function applyCampaign($order): Order
    {
        $steps = [
            10000000,
            30000000,
        ];

        $discounts = [
            10,
            20,
            30
        ];

        $payable = $order->total - $order->discount_amount;

        if ($payable > $steps[1]) {
            $order->discount_amount = $payable * $discounts[2] / 100;
        } elseif ($payable > $steps[0]) {
            $order->discount_amount = $payable * $discounts[1] / 100;
        } else {
            $order->discount_amount = $payable * $discounts[0] / 100;
        }

        $order->save();

        return $order;
    }
}

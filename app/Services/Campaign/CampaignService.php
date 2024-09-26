<?php

namespace App\Services\Campaign;

use App\Models\Order;

class CampaignService
{
    public static function calculate($payable)
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

        $campaign_amount = 0;
        if ($payable >= $steps[1]) {
            $campaign_amount = $payable * $discounts[2] / 100;
        } elseif ($payable >= $steps[0]) {
            $campaign_amount = $payable * $discounts[1] / 100;
        } else {
            $campaign_amount = $payable * $discounts[0] / 100;
        }

        return $campaign_amount;
    }
}

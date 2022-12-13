<?php

namespace App\Services\Payment\Drivers;

use App\Services\Payment\Drivers\PaymentDriver;

class ZarrinpalAdaptor implements PaymentDriver
{
    public function pay($payment)
    {
        $MerchantID = config('services.zarinpal.merchant_id');
        $SandBox = config('services.zarinpal.sandbox');
        // $res = Zarrinpal::pay($MerchantID, $payment->amount, $SandBox);

        // dd($res);
    }

    public function verify($payment)
    {
        $MerchantID = config('services.zarinpal.merchant_id');
        $SandBox = config('services.zarinpal.sandbox');
        $res = Zarrinpal::verify($MerchantID, $payment->amount, $SandBox);

        dd($res);
    }
}

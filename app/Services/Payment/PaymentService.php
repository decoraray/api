<?php

namespace App\Services\Payment;

use App\Models\Payment;

class PaymentService
{

    public function __construct()
    {
        //
    }
    public function verify($uuid)
    {
        $payment = Payment::where('uuid', $uuid)->first();

        // $MerchantID = config('services.zarinpal.merchant_id');
        // $SandBox = config('services.zarinpal.sandbox');
        // $res = $this->driver::verify($MerchantID, $payment->amount, $SandBox);

        return response()->json([
            'message' => 'payment successful',
        ]);
    }

    public function pay($payment)
    {;

        // driver::pay($payment);
        // dd('paying');

        return response()->json([
            'message' => 'payment successful',
        ]);
    }
}

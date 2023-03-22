<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class PaypingDriver implements GatewayInterface
{
    private $token;
    private $callback;
    private $url;


    public function __construct()
    {
        $this->token = config('services.payping.token');
        $this->url = config('services.payping.url');
        // $this->callback = route('payment-verify', ['gateway' => 'payping']);
        $this->callback = config('app.url') . '/api/v1/payment-verify?gateway=payping';
    }

    public function Pay(Payment $payment): string
    {

        // step 1 
        $refId = $this->getRefId($payment);

        // step 2 
        return $this->url . '/v2/pay/gotoipg/' . $refId;
    }

    private function getRefId($payment)
    {
        $user = $payment->user;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->url . '/v2/pay', [
            'payerName' => $user->name,
            // 'amount' => (int)$payment->amount,
            'amount' => 1000, // TODO : remove this hard coded value
            'payerIdentity' => $payment->user->mobile,
            'returnUrl' => $this->callback,
            'clientRefId' => $payment->uuid,
            'description' => 'پرداخت از طریق سایت',
        ])->throw()->json();

        return $response['code'];
    }

    public function verify($payment)
    {

        // Request to callback link from payping 
        // array:6 [▼ // app/Http/Controllers/PaymentController.php:133
        //     "code" => "a4c17db"
        //     "refid" => "80037571456"
        //     "clientrefid" => "6419914b94171"
        //     "cardnumber" => null
        //     "cardhashpan" => "63144DDD215E8B0002BF6064FD67D5893F1361B8FB70855C2C32E52A7439F54E"
        //     "gateway" => "payping"
        //     ]

        // response of '/v2/pay/verify' 
        // array:4 [▼ // app/Services/Payment/Gateways/PaypingDriver.php:67
        //     "amount" => 1000
        //     "cardNumber" => "603799******6680"
        //     "cardHashPan" => "63144DDD215E8B0002BF6064FD67D5893F1361B8FB70855C2C32E52A7439F54E"
        //     "merchants" => []
        // ]

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->url . '/v2/pay/verify', [
            'RefId' => $payment->ref_id,
            // 'amount' => $payment->amount
            'amount' => 1000 // TODO : remove this hard coded value
        ])->throw()->json();

        if ($response['amount'] == $payment->amount || $response['amount'] == 1000) { // TODO : remove this hard coded value
            $payment->update([
                'status' => 'paid',
                'card_number' => $response['cardNumber'],
                'card_pan' => $response['cardHashPan'],
            ]);
            return $payment;
        } else {
            throw new \Exception('payment failed');
        }
    }

    public function getName(): string
    {
        return 'payping';
    }
}

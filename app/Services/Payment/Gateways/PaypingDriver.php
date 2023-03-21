<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;

class PaypingDriver implements GatewayInterface
{
    private $token;
    private $callback;
    private $url;

    private $hardCodedUrl = 'https://decoraray-api.mamoyz.dev/api/v1/payment-verify?gateway=payping';

    public function __construct()
    {
        $this->token = config('services.payping.token');
        $this->url = config('services.payping.url');
        // $this->callback = route('payment-verify', ['gateway' => 'payping']);
        $this->callback = $this->hardCodedUrl; // TODO : make it dynamic
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
            'amount' => 1000,
            'payerIdentity' => $payment->user->mobile,
            'returnUrl' => $this->callback,
            'clientRefId' => $payment->uuid,
            'description' => 'پرداخت از طریق سایت',
        ])->throw()->json();

        return $response['code'];
    }

    public function verify($payment)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->url . '/v2/pay/verify', [
            'RefId' => $payment->ref_id,
            'amount' => $payment->amount
        ])->throw()->json();

        if ($response['code'] == 200) {
            $payment->update([
                'status' => 'paid',
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

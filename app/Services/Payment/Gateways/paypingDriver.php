<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redirect;
use Termwind\Components\Dd;

class PaypingDriver implements GatewayInterface
{
    private $token;
    private $callback;
    private $url;

    public function __construct()
    {
        $this->token = config('services.payping.token');
        $this->url = config('services.payping.url');
        $this->callback = route('payment-verify', ['gateway' => 'payping']);
    }

    public function Pay(Payment $payment)
    {
        // step 1 
        $refId = $this->getRefId($payment);
        $payment->update([
            'ref_id' => $refId,
        ]);
        // dd($payment, $refId);

        // step 2 
        return Redirect::to($this->url . '/v2/pay/gotoipg/' . $refId);
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
            'amount' => $payment->amount,
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
            'amount' => strval($payment->amount)
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

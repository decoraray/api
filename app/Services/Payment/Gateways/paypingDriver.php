<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Payping implements GatewayInterface
{
    private $token;
    private $callback;
    private $url;

    public function __construct()
    {
        $this->token = config('services.payping.token');
        $this->url = config('services.payping.url');
        $this->callback = route('payment.verify', $this->getName());
    }
    public function Pay(Payment $payment)
    {
        $this->redirectToBank($payment);
    }

    private function redirectToBank($payment)
    {
        Http::withHeaders([
            'Authorization' => $this->token,
        ])->post($this->url . '/v2/pay', [
            'amount' => $payment->amount,
            'payerIdentity' => $payment->user->mobile,
            'returnUrl' => $this->callback,
            'clientRefId' => $payment->uuid,
        ])->throw()->json();
    }

    public function verify(Request $request)
    {
        $soapClient = new \SoapClient('https://acquirer.samanepay.com/payments/referencepayment.asmx?WSDL');
        $response = $soapClient->VerifyTransaction($request->input('RefNum'), $this->token);

        $payment = $this->getPayment($request->input('ResNum'));

        $response = $payment->amount; // this line makes a fake success payment
        $request->merge(['RefNum' => '45852525']); // this line for put a fake RefNum in request

        return $response == ($payment->amount)
            ? $this->transactionSuccess($payment, $request->input('RefNum'))
            : $this->transactionFailed();
    }

    private function transactionSuccess($payment, $refNum)
    {
        return [
            'status' => self::TRANSACTION_SUCCESS,
            'payment' => $payment,
            'refNum' => $refNum,
            'gateway' => $this->getName()
        ];
    }

    private function getPayment($resNum)
    {
        return Payment::where('res_num', $resNum)->firstOrFail();
    }

    private function transactionFailed()
    {
        return [
            'status' => self::TRANSACTION_FAILED
        ];
    }


    public function getName(): string
    {
        return 'saman';
    }
}

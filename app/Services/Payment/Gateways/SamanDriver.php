<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use Illuminate\Http\Request;


class Saman implements GatewayInterface
{
    private $merchantID;
    private $callback;

    public function __construct()
    {
        $this->merchantID = '452585658';
        $this->callback = route('payment.verify', $this->getName());
    }
    public function Pay(Payment $payment)
    {
        $this->redirectToBank($payment);
    }

    private function redirectToBank($payment)
    {
        echo "<form id='samanpeyment' action='https://sep.shaparak.ir/payment.aspx' method='post'>
		<input type='hidden' name='Amount' value='{$payment->amount}' />
		<input type='hidden' name='ResNum' value='{$payment->uuid}'>
		<input type='hidden' name='RedirectURL' value='{$this->callback}'/>
		<input type='hidden' name='MID' value='{$this->merchantID}'/>
		</form><script>document.forms['samanpeyment'].submit()</script>";
    }

    public function verify(Request $request)
    {
        $soapClient = new \SoapClient('https://acquirer.samanepay.com/payments/referencepayment.asmx?WSDL');
        $response = $soapClient->VerifyTransaction($request->input('RefNum'), $this->merchantID);

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

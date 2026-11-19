<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use App\Services\Payment\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaypingDriver implements GatewayInterface
{
    private $token;
    private $callback;
    private $url;

    public function __construct()
    {
        $this->token = config('services.payping.token');
        $this->url = config('services.payping.url');
        $this->callback = config('app.url') . '/api/v1/payment-verify?gateway=payping';
    }

    public function Pay(Payment $payment): string
    {

        // step 1 
        $refId = $this->getRefId($payment);

        // step 2 
        return $this->url . '/v3/pay/start/' . $refId;
    }

    private function getRefId($payment)
    {
        $user = $payment->user;

        if (config('services.payping.test')) {
            $amount = 1000;
        } else {
            $amount = (int)$payment->amount;
        }

        // Fields per PayPing's PaymentModel (statics.payping.ir/openapi.json), which
        // rejects unknown properties. Amounts are in Toman.
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(30)->post($this->url . '/v3/pay', [
                'payerName' => $user?->name,
                'amount' => $amount,
                'payerIdentity' => $user?->phone,
                'returnUrl' => $this->callback,
                'clientRefId' => $payment->uuid,
                'description' => 'پرداخت از طریق سایت',
            ]);
        } catch (ConnectionException $e) {
            $this->failPayment($payment, ['message' => $e->getMessage()]);
            throw new PaymentGatewayException('PayPing unreachable: ' . $e->getMessage(), 'payping', null, null, $e);
        }

        if ($response->failed() || empty($response->json('paymentCode'))) {
            $body = $response->json() ?? $response->body();
            $this->failPayment($payment, $body);

            // PayPing errors are RFC 7807 problem details: title (e.g. PolicyException,
            // ValidationException), detail, metaData.errors[].message, paypingTraceId.
            $errors = collect($response->json('metaData.errors') ?? [])->pluck('message')->filter()->implode('; ');
            $message = sprintf(
                'PayPing /v3/pay failed (%d %s): %s [paypingTraceId: %s]',
                $response->status(),
                $response->json('title') ?? 'error',
                trim(($response->json('detail') ?? '') . ' ' . $errors) ?: mb_substr($response->body(), 0, 500),
                $response->json('paypingTraceId') ?? '-',
            );

            Log::error($message, ['payment' => $payment->uuid, 'response' => $body]);

            throw new PaymentGatewayException($message, 'payping', $response->status(), $body);
        }

        return $response->json('paymentCode');
    }

    private function failPayment(Payment $payment, mixed $error): void
    {
        // payments.error is a varchar(255) with no cast
        $text = is_string($error) ? $error : json_encode($error, JSON_UNESCAPED_UNICODE);

        $payment->update([
            'status' => 'failed',
            'error' => mb_substr((string) $text, 0, 255),
        ]);
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

        // response of '/v3/pay/verify' 
        // array:4 [▼ // app/Services/Payment/Gateways/PaypingDriver.php:67
        //     "amount" => 1000
        //     "cardNumber" => "603799******6680"
        //     "cardHashPan" => "63144DDD215E8B0002BF6064FD67D5893F1361B8FB70855C2C32E52A7439F54E"
        //     "merchants" => []
        // ]

        if (config('services.payping.test')) {
            $amount = 1000;
        } else {
            $amount = (int)$payment->amount;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->url . '/v3/pay/verify', [
            'paymentRefId' => $payment->ref_id,
            'paymentCode' => $payment->payment_code,
            'amount' => $amount
        ]);

        Log::info('Payment Payping verification response received', ['status' => $response->status(), 'response' => $response->json() ?? $response->body()]);

        if ($response->successful() && $response['amount'] == $amount) {
            if (isset($response['status']) && $response['status'] == '0') {
                $this->failPayment($payment, $response->json() ?? $response->body());
            } else {
                $payment->update([
                    'status' => 'paid',
                    'card_number' => $response['cardNumber'] ?? null,
                    'card_pan' => $response['cardHashPan'] ?? null,
                ]);
            }
        } else {
            $this->failPayment($payment, $response->json() ?? $response->body());
        }

        return $payment;
    }

    public function getName(): string
    {
        return 'payping';
    }
}

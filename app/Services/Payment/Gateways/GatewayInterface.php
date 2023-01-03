<?php

namespace App\Services\Payment\Gateways;


use App\Models\Payment;
use Illuminate\Http\Request;

interface GatewayInterface
{
    const TRANSACTION_FAILED = 'transaction.failed';
    const TRANSACTION_SUCCESS = 'transaction.success';

    public function Pay(Payment $payment);
    public function verify(Request $request);
    public function getName(): string;
}

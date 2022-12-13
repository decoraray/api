<?php

namespace App\Services\Payment\Drivers;

interface PaymentDriver
{
    public function pay($payment);
    public function verify($payment);
}

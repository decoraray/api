<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Services\Payment\Gateways\GatewayInterface;
use Illuminate\Http\Request;

class PaymentService
{

    public function __construct()
    {
        //
    }
    public function verify($payment, $gateway)
    {
        $driver =  $this->getDriver($gateway);

        return $driver->verify($payment);
    }

    public function pay($payment, $gateway): string
    {
        $driver =  $this->getDriver($gateway);
        return $driver->pay($payment);
    }

    private function  getDriver($gateway): GatewayInterface
    {
        // get class of driver by name of gateway
        $driver = 'App\Services\Payment\Gateways\\' . ucfirst($gateway) . 'Driver';

        // check if driver exists
        if (!class_exists($driver)) {
            throw new \Exception('driver not found');
        }

        // create new instance of driver
        return new $driver;
    }
}

<?php

namespace App\Services\Sms;

use App\Services\Sms\Drivers\SmsIr;

// use App\Services\Sms\Drivers\DriverInterface;

class SmsService
{
    public function __construct(
        protected SmsIr $driver
    ) {
    }

    public function send(string $phone, string $message): bool
    {
        return $this->driver->send($phone, $message);
    }

    public function verify(string $phone, string $message): bool
    {
        return $this->driver->verify($phone, $message);
    }

    public function getBalance(): float
    {
        return $this->driver->getBalance();
    }
}

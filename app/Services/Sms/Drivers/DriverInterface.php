<?php

namespace App\Services\Sms\Drivers;

interface DriverInterface
{
    public static function send(string $phone, string $message): bool;
    public static function verify(string $phone, string $code): bool;
    public static function getBalance(): float;
}

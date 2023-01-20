<?php

namespace App\Services\Sms\Drivers;

use App\Services\Sms\Drivers\DriverInterface;
use Illuminate\Support\Facades\Http;

class SmsIr implements DriverInterface
{
    public static function send(string $phone, string $message): bool
    {
        return true;
    }

    public static function verify(string $phone, string $code): bool
    {
        Http::post(config('services.sms_provider') . 'send/verify', [
            'Mobile' => [$phone],
            'TemplateId' =>  10000,
            'Parameters' => [
                'code' => $code,
            ],
        ])->withHeaders([
            'accept' => 'application/json',
            'X-API-KEY' => config('services.sms_provider.app_key'),
        ])->throw()->json();

        return true;
    }

    public static function getBalance(): float
    {
        return 0;
    }
}

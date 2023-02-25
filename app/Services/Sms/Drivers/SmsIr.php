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
        $response = Http::withHeaders([
            'accept' => 'application/json',
            'X-API-KEY' => config('services.sms_provider.app_key'),
        ])->post(config('services.sms_provider.url') . 'send/verify', [
            'mobile' => $phone,
            'templateId' =>  1,
            'parameters' => [
                [
                    'name' => 'code',
                    'value' => $code
                ],
            ],
        ]);

        if ($response->failed()) {
            log($response->body());
            return false;
        }

        return true;
    }

    public static function getBalance(): float
    {
        return 0;
    }
}

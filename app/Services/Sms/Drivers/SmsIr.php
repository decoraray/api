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
        $normalized = preg_replace('/\D/', '', $phone);
        if (strlen($normalized) === 11 && $normalized[0] === '0') {
            $normalized = substr($normalized, 1);
        }
        if (strlen($normalized) !== 10 || $normalized[0] !== '9') {
            throw new \InvalidArgumentException('Invalid Iranian mobile number. Expected 9xxxxxxxxx or 09xxxxxxxxx.');
        }
        $mobile = '0' . $normalized;

        $response = Http::withHeaders([
            'accept' => 'application/json',
            'X-API-KEY' => config('services.sms_provider.app_key'),
        ])->post(config('services.sms_provider.url') . 'send/verify', [
            'mobile' => $mobile,
            'templateId' =>  269541,
            'parameters' => [
                [
                    'name' => 'CODE',
                    'value' => $code
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception($response->body());
        }

        return true;
    }

    public static function getBalance(): float
    {
        return 0;
    }
}
